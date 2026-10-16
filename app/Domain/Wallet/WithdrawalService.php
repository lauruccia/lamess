<?php

declare(strict_types=1);

namespace App\Domain\Wallet;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use App\Domain\Payouts\FiscalCalculator;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Payout;
use App\Models\Period;
use App\Models\WithdrawalRequest;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Prelievi dal wallet su richiesta (RF-086…087). Attivi solo se il piano in vigore ha la modalità di pagamento «su richiesta».
 *
 *  richiesto → approvato (nasce il pagamento «da pagare», con SEPA e CSV come gli altri) → pagato | respinto | annullato
 *
 * Soglia minima del piano (15 €), importo ≤ quota disponibile, commissione bonifico (2,50 €) e trattenute fiscali sono
 * calcolate alla richiesta e ricalcolate all'approvazione. Il pagamento va fatto entro 5 giorni lavorativi dalla richiesta.
 */
final class WithdrawalService
{
    public const BUSINESS_DAYS = 5;

    public function __construct(private readonly WalletService $wallet) {}

    /** True se il piano in vigore prevede i prelievi su richiesta. */
    public static function enabled(): bool
    {
        return CompPlanVersion::inForce()?->plan()->payoutMode === 'on_request';
    }

    /** Scadenza di pagamento: N giorni lavorativi (sabato e domenica esclusi) dalla richiesta. */
    public static function dueBy(CarbonImmutable $from, int $days = self::BUSINESS_DAYS): CarbonImmutable
    {
        $d = $from->setTimezone('Europe/Rome')->startOfDay();
        while ($days > 0) {
            $d = $d->addDay();
            if (! $d->isWeekend()) {
                $days--;
            }
        }

        return $d;
    }

    public function request(Consultant $c, BigDecimal|string $amount): WithdrawalRequest
    {
        if (! self::enabled()) {
            throw new WalletException('I prelievi su richiesta non sono attivi: i compensi vengono pagati ogni mese in automatico.');
        }
        $amount = Num::money($amount);

        // Controllo del saldo e creazione della richiesta nella stessa transazione, con il consulente bloccato:
        // due richieste simultanee non possono impegnare entrambe la stessa quota disponibile.
        return DB::transaction(function () use ($c, $amount): WithdrawalRequest {
            $c = Consultant::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $sit = $this->wallet->situation($c);
            if ($sit['reasons'] !== []) {
                throw new WalletException('Non puoi prelevare: '.implode(', ', $sit['reasons']).'.');
            }
            $min = CompPlanVersion::inForce()->plan()->minPayout;
            if ($amount->isLessThan($min)) {
                throw new WalletException("Il prelievo minimo è di {$min} €.");
            }
            if ($amount->isGreaterThan($sit['available'])) {
                throw new WalletException("Puoi prelevare al massimo {$sit['available']} €.");
            }
            $f = $this->fiscal($c, $amount);

            $r = WithdrawalRequest::create([
                'consultant_id' => $c->id, 'gross' => (string) $amount, 'withholding' => $f['withholding'], 'inps' => $f['inps_worker'], 'fee' => $f['fee'], 'net' => $f['net'],
                'iban' => (string) $c->iban, 'iban_holder' => $c->iban_holder, 'status' => WithdrawalRequest::REQUESTED, 'requested_at' => now(),
                'due_by' => self::dueBy(CarbonImmutable::now())->format('Y-m-d'),
            ]);
            AuditLog::record('withdrawal.requested', "Prelievo di {$amount} € richiesto da {$c->code}", $r, ['netto' => $f['net']]);

            return $r;
        });
    }

    /** L’incaricato ritira la richiesta finché non è approvata. */
    public function cancel(WithdrawalRequest $r): WithdrawalRequest
    {
        if ($r->status !== WithdrawalRequest::REQUESTED) {
            throw new WalletException('Solo una richiesta non ancora approvata si può annullare.');
        }
        $r->update(['status' => WithdrawalRequest::CANCELLED]);
        AuditLog::record('withdrawal.cancelled', "Prelievo annullato da {$r->consultant->code}", $r);

        return $r;
    }

    public function reject(WithdrawalRequest $r, string $reason): WithdrawalRequest
    {
        if (! in_array($r->status, [WithdrawalRequest::REQUESTED], true)) {
            throw new WalletException('Si respinge solo una richiesta in attesa.');
        }
        if (trim($reason) === '') {
            throw new WalletException('Il motivo è obbligatorio.');
        }
        $r->update(['status' => WithdrawalRequest::REJECTED, 'rejection_reason' => $reason, 'reviewed_by' => Actor::label()]);
        AuditLog::record('withdrawal.rejected', "Prelievo di {$r->consultant->code} respinto", $r, [], $reason);

        return $r;
    }

    /** Approva: ricalcola, ricontrolla il saldo e crea il pagamento «da pagare». */
    public function approve(WithdrawalRequest $r): WithdrawalRequest
    {
        if ($r->status !== WithdrawalRequest::REQUESTED) {
            throw new WalletException('Si approva solo una richiesta in attesa.');
        }

        return DB::transaction(function () use ($r): WithdrawalRequest {
            $c = Consultant::whereKey($r->consultant_id)->lockForUpdate()->firstOrFail();
            if ($r->fresh()?->status !== WithdrawalRequest::REQUESTED) {
                throw new WalletException('Si approva solo una richiesta in attesa.');
            }
            $sit = $this->wallet->situation($c, $r->id);
            if ($sit['reasons'] !== []) {
                throw new WalletException('Non approvabile: '.implode(', ', $sit['reasons']).'.');
            }
            if (Num::of((string) $r->gross)->isGreaterThan($sit['available'])) {
                throw new WalletException("Il saldo disponibile ({$sit['available']} €) non copre più la richiesta.");
            }
            $period = Period::where('status', Period::CLOSED)->orderByDesc('code')->first()
                ?? throw new WalletException('Non c\'è nessun mese chiuso a cui collegare il pagamento.');
            $f = $this->fiscal($c, Num::of((string) $r->gross));
            if (Num::of($f['net'])->isLessThanOrEqualTo(0)) {
                throw new WalletException('Dopo trattenute e commissione non resta nulla da pagare.');
            }
            $payout = Payout::create([
                'consultant_id' => $c->id, 'period_id' => $period->id, 'amount' => $f['net'], 'gross_amount' => $r->gross, 'withholding_amount' => $f['withholding'],
                'inps_amount' => $f['inps_worker'], 'fee_amount' => $f['fee'], 'fiscal_year' => (int) CarbonImmutable::now('Europe/Rome')->format('Y'),
                'iban' => $r->iban, 'iban_holder' => $r->iban_holder, 'status' => Payout::TO_PAY, 'prepared_by' => Actor::label(),
            ]);
            $r->update([
                'status' => WithdrawalRequest::APPROVED, 'payout_id' => $payout->id, 'approved_at' => now(), 'reviewed_by' => Actor::label(),
                'withholding' => $f['withholding'], 'inps' => $f['inps_worker'], 'fee' => $f['fee'], 'net' => $f['net'],
            ]);
            AuditLog::record('withdrawal.approved', "Prelievo di {$r->gross} € di {$c->code} approvato (netto {$f['net']} €)", $r, ['pagamento' => $payout->id]);

            return $r;
        });
    }

    /** Chiamato da PayoutService::markPaid: scrive i movimenti del wallet e chiude la richiesta. */
    public function onPaid(Payout $payout): void
    {
        $r = WithdrawalRequest::where('payout_id', $payout->id)->first();
        if ($r === null) {
            return;
        }
        $c = $payout->consultant;
        $refs = ['payout_id' => $payout->id, 'withdrawal_request_id' => $r->id];
        $when = $payout->paid_at?->setTimezone('Europe/Rome')->format('d/m/Y') ?? now('Europe/Rome')->format('d/m/Y');
        foreach ([['withholding_tax', $payout->withholding_amount, 'Ritenuta d\'acconto'], ['inps', $payout->inps_amount, 'Contributo INPS (quota incaricato)'], ['transfer_fee', $payout->fee_amount, 'Commissione bonifico']] as [$type, $amount, $label]) {
            if (Num::of((string) $amount)->isPositive()) {
                $this->wallet->post($c, $type, Num::of((string) $amount)->negated(), "{$label} — prelievo del {$when}", $refs);
            }
        }
        $this->wallet->post($c, 'withdrawal', Num::of((string) $payout->amount)->negated(), "Prelievo pagato il {$when}", $refs);
        $r->update(['status' => WithdrawalRequest::PAID, 'paid_at' => $payout->paid_at]);
    }

    /** Chiamato da PayoutService::cancel: la richiesta torna in attesa di una nuova approvazione. */
    public function onPayoutCancelled(Payout $payout): void
    {
        WithdrawalRequest::where('payout_id', $payout->id)->where('status', WithdrawalRequest::APPROVED)
            ->update(['status' => WithdrawalRequest::REQUESTED, 'payout_id' => null]);
    }

    /** @return array<string,string> */
    private function fiscal(Consultant $c, BigDecimal $gross): array
    {
        $year = (int) CarbonImmutable::now('Europe/Rome')->format('Y');

        return FiscalCalculator::compute($c->fiscal_regime ?? 'occasional', $gross, FiscalCalculator::ytdGross($c, $year), (string) config('lamess.fiscal.bank_fee'));
    }
}
