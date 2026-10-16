<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\Settlement;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * Wallet provvigionale (RF-083): registro dei movimenti, il saldo è sempre la somma dei movimenti (calcolata dal database con SUM, senza caricare le righe).
 * Si usa solo con la modalità di pagamento «su richiesta»: alla chiusura del mese le liquidazioni diventano accrediti.
 * Un saldo negativo (storni) resta a debito e si compensa con gli accrediti successivi.
 */
final class WalletService
{
    public function balance(int $consultantId): BigDecimal
    {
        return Num::money(BigDecimal::of((string) (WalletTransaction::where('consultant_id', $consultantId)->sum('amount') ?? 0)));
    }

    /** Importo già impegnato da prelievi richiesti o approvati e non ancora pagati. */
    public function reserved(int $consultantId, ?int $exceptRequestId = null): BigDecimal
    {
        $sum = WithdrawalRequest::where('consultant_id', $consultantId)->whereIn('status', [WithdrawalRequest::REQUESTED, WithdrawalRequest::APPROVED])
            ->when($exceptRequestId, fn ($q) => $q->where('id', '!=', $exceptRequestId))->sum('gross');

        return Num::money(BigDecimal::of((string) ($sum ?? 0)));
    }

    /**
     * Saldo, quota disponibile e quota bloccata (KYC non verificato, IBAN mancante, pagamenti sospesi) con i motivi.
     *
     * @return array{balance:string, reserved:string, available:string, blocked:string, reasons:list<string>}
     */
    public function situation(Consultant $c, ?int $exceptRequestId = null): array
    {
        $balance = $this->balance($c->id);
        $reserved = $this->reserved($c->id, $exceptRequestId);
        $reasons = [];
        if ($c->kyc_status !== 'verified') {
            $reasons[] = 'KYC non verificato';
        }
        if (blank($c->iban)) {
            $reasons[] = 'IBAN mancante';
        }
        if ($c->bonus_hold) {
            $reasons[] = 'pagamenti sospesi';
        }
        if ($c->status === 'terminated') {
            $reasons[] = 'incarico cessato';
        }
        $free = Num::max(Num::zero(), $balance->minus($reserved));
        $blocked = $reasons === [] ? Num::zero() : Num::max(Num::zero(), $balance);

        return [
            'balance' => (string) $balance, 'reserved' => (string) $reserved, 'available' => $reasons === [] ? (string) Num::money($free) : '0.00',
            'blocked' => (string) Num::money($blocked), 'reasons' => $reasons,
        ];
    }

    /**
     * Accredita al wallet le liquidazioni di un mese chiuso (solo con modalità «su richiesta»). Idempotente.
     *
     * @return int numero di accrediti scritti
     */
    public function creditPeriod(Period $period): int
    {
        if ($period->planVersion->plan()->payoutMode !== 'on_request') {
            return 0;
        }
        $n = 0;
        DB::transaction(function () use ($period, &$n): void {
            foreach (Settlement::where('period_id', $period->id)->where('status', 'open')->whereNull('payout_id')->whereNull('wallet_credited_at')->get() as $s) {
                $amount = Num::money((string) $s->accrued);
                if (! $amount->isZero()) {
                    $this->post($s->consultant, $amount->isNegative() ? 'clawback' : 'commission_credit', $amount,
                        'Provvigioni '.$period->code, ['period_id' => $period->id, 'settlement_id' => $s->id]);
                }
                $s->update(['status' => 'credited', 'wallet_credited_at' => now()]);
                $n++;
            }
        });
        if ($n > 0) {
            AuditLog::record('wallet.credited', "Wallet accreditato per {$period->code}: {$n} incaricati", $period, ['accrediti' => $n]);
        }

        return $n;
    }

    /** Un mese non si riapre se dopo la sua chiusura sono stati pagati prelievi dal wallet. */
    public function assertReversible(Period $period): void
    {
        $closedAt = $period->closed_at;
        if ($closedAt !== null && WalletTransaction::where('type', 'withdrawal')->where('created_at', '>=', $closedAt)->exists()) {
            throw new WalletException('Dopo la chiusura di questo mese sono stati pagati prelievi dal wallet: non si può riaprire. Correggi con una rettifica.');
        }
    }

    /** Annulla gli accrediti di un mese che si riapre e rimette le liquidazioni «aperte». */
    public function reversePeriod(Period $period): void
    {
        Settlement::where('period_id', $period->id)->where('status', 'credited')->update(['status' => 'open', 'wallet_credited_at' => null]);
        WalletTransaction::where('period_id', $period->id)->whereIn('type', ['commission_credit', 'clawback'])->delete();
        $this->recomputeBalances(WalletTransaction::query()->distinct()->pluck('consultant_id')->all());
    }

    /** Rettifica manuale: motivo obbligatorio, sempre nel log di audit. */
    public function adjust(Consultant $c, BigDecimal|string $amount, string $reason): WalletTransaction
    {
        $amount = Num::money($amount);
        if ($amount->isZero()) {
            throw new WalletException('L\'importo della rettifica non può essere zero.');
        }
        if (trim($reason) === '') {
            throw new WalletException('Il motivo della rettifica è obbligatorio.');
        }
        $tx = $this->post($c, 'manual_adjustment', $amount, 'Rettifica: '.$reason);
        AuditLog::record('wallet.adjusted', "Rettifica di {$amount} € sul wallet di {$c->code}", $c, ['importo' => (string) $amount], $reason);

        return $tx;
    }

    /** @param array{period_id?:int, settlement_id?:int, payout_id?:int, withdrawal_request_id?:int} $refs */
    public function post(Consultant $c, string $type, BigDecimal|string $amount, string $description, array $refs = []): WalletTransaction
    {
        if (! isset(WalletTransaction::TYPES[$type])) {
            throw new WalletException("Tipo di movimento sconosciuto: {$type}");
        }

        return DB::transaction(function () use ($c, $type, $amount, $description, $refs): WalletTransaction {
            Consultant::whereKey($c->id)->lockForUpdate()->first(); // serializza le scritture sullo stesso wallet
            $amount = Num::money($amount);
            $last = WalletTransaction::where('consultant_id', $c->id)->orderByDesc('id')->value('balance_after');

            return WalletTransaction::create([
                'consultant_id' => $c->id, 'type' => $type, 'amount' => (string) $amount, 'description' => $description, 'created_by' => Actor::label(),
                'balance_after' => (string) Num::money(Num::of((string) ($last ?? '0'))->plus($amount)),
            ] + $refs);
        });
    }

    /** @param list<int> $consultantIds */
    private function recomputeBalances(array $consultantIds): void
    {
        foreach ($consultantIds as $id) {
            $run = BigDecimal::zero();
            foreach (WalletTransaction::where('consultant_id', $id)->orderBy('id')->get() as $t) {
                $run = $run->plus((string) $t->amount);
                $t->update(['balance_after' => (string) Num::money($run)]);
            }
        }
    }
}
