<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Domain\Onboarding\Iban;
use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use App\Domain\Wallet\WithdrawalService;
use App\Models\CalculationRun;
use App\Models\CommissionStatement;
use App\Models\Consultant;
use App\Models\Payout;
use App\Models\Period;
use App\Models\Settlement;
use App\Notifications\PayoutPaid;
use App\Notifications\StatementReady;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Liquidazioni e pagamenti (Fase 6).
 *
 *  1. Alla chiusura del periodo le righe bloccate del runtime ufficiale diventano una liquidazione per incaricato.
 *  2. «Prepara pagamenti» somma le liquidazioni aperte dell’incaricato (anche dei mesi precedenti): sopra la soglia
 *     minima del piano e senza blocchi crea un pagamento «da pagare»; altrimenti il saldo resta visibile e si cumula.
 *  3. «Segna pagato» registra data e riferimento del bonifico. Nessun denaro si muove da qui: il bonifico si fa in banca.
 */
final class PayoutService
{
    public const BELOW_MIN = 'below_min';

    public const NEGATIVE = 'negative';

    public const BLOCKED = 'blocked';

    public const READY = 'ready';

    /**
     * Crea (o aggiorna) le liquidazioni di un periodo chiuso. Idempotente: le liquidazioni già collegate a un pagamento non si toccano.
     *
     * @return int numero di liquidazioni scritte
     */
    public function generateSettlements(Period $period): int
    {
        if (! $period->isClosed()) {
            throw new PayoutException("Il periodo {$period->code} non è chiuso: le liquidazioni si generano dopo la chiusura.");
        }
        $run = CalculationRun::find($period->official_run_id);
        if ($run === null) {
            throw new PayoutException("Il periodo {$period->code} non ha un runtime ufficiale.");
        }

        $sums = [];
        foreach (DB::table('commission_lines')->where('run_id', $run->id)->where('status', 'locked')->get(['beneficiary_id', 'amount', 'engine_status']) as $l) {
            $s = &$sums[$l->beneficiary_id];
            $s ??= ['accrued' => BigDecimal::zero(), 'held' => BigDecimal::zero(), 'n' => 0];
            if ($l->engine_status === 'accrued') {
                $s['accrued'] = $s['accrued']->plus((string) $l->amount);
            } elseif ($l->engine_status === 'held') {
                $s['held'] = $s['held']->plus((string) $l->amount);
            }
            $s['n']++;
            unset($s);
        }

        $written = 0;
        DB::transaction(function () use ($period, $sums, &$written): void {
            foreach ($sums as $consultantId => $s) {
                $existing = Settlement::where('period_id', $period->id)->where('consultant_id', $consultantId)->first();
                if ($existing !== null && ($existing->payout_id !== null || in_array($existing->status, ['paid', 'credited'], true))) {
                    continue;
                }
                Settlement::updateOrCreate(
                    ['period_id' => $period->id, 'consultant_id' => $consultantId],
                    ['accrued' => (string) Num::money($s['accrued']), 'held' => (string) Num::money($s['held']), 'lines_count' => $s['n'], 'status' => 'open'],
                );
                $written++;
            }
        });
        AuditLog::record('settlements.generated', "Liquidazioni generate per {$period->code}: {$written}", $period, ['liquidazioni' => $written]);

        return $written;
    }

    /** Saldo ancora da pagare di un incaricato: somma delle liquidazioni aperte non collegate a un pagamento. */
    public function openBalance(int $consultantId, ?string $upToPeriodCode = null): BigDecimal
    {
        $q = Settlement::where('consultant_id', $consultantId)->where('status', 'open')->whereNull('payout_id');
        if ($upToPeriodCode !== null) {
            $q->whereHas('period', fn ($p) => $p->where('code', '<=', $upToPeriodCode));
        }
        $sum = BigDecimal::zero();
        foreach ($q->pluck('accrued') as $a) {
            $sum = $sum->plus($a);
        }

        return Num::money($sum);
    }

    /**
     * Situazione di un saldo: pronto al pagamento, sotto soglia, negativo o bloccato (con motivo).
     *
     * @return array{state:string, reasons:list<string>}
     */
    public function situation(Consultant $c, BigDecimal $balance, BigDecimal $minPayout): array
    {
        $reasons = [];
        if ($c->kyc_status !== 'verified') {
            $reasons[] = 'KYC non verificato';
        }
        if (blank($c->iban)) {
            $reasons[] = 'IBAN mancante';
        } elseif (! Iban::isSepa((string) $c->iban) && blank($c->bic)) {
            $reasons[] = 'BIC/SWIFT mancante (IBAN fuori area SEPA)';
        }
        if ($c->bonus_hold) {
            $reasons[] = 'pagamenti sospesi';
        }
        if ($balance->isNegative()) {
            return ['state' => self::NEGATIVE, 'reasons' => ['saldo negativo: si compensa con i mesi successivi']];
        }
        if ($balance->isLessThan($minPayout)) {
            return ['state' => self::BELOW_MIN, 'reasons' => array_merge(["sotto la soglia di {$minPayout} €: si cumula"], $reasons)];
        }
        if ($reasons !== []) {
            return ['state' => self::BLOCKED, 'reasons' => $reasons];
        }

        return ['state' => self::READY, 'reasons' => []];
    }

    /**
     * Prepara i pagamenti fino al periodo indicato (incluso).
     *
     * @return array{created:int, amount:string, below_min:int, negative:int, blocked:int}
     */
    public function preparePayouts(Period $upTo): array
    {
        if (! $upTo->isClosed()) {
            throw new PayoutException("Il periodo {$upTo->code} non è chiuso.");
        }
        $min = $upTo->planVersion->plan()->minPayout;
        $out = ['created' => 0, 'amount' => '0.00', 'below_min' => 0, 'negative' => 0, 'blocked' => 0];
        $total = BigDecimal::zero();
        $by = $this->actorLabel();

        $ids = Settlement::where('status', 'open')->whereNull('payout_id')
            ->whereHas('period', fn ($p) => $p->where('code', '<=', $upTo->code))->distinct()->pluck('consultant_id');

        foreach (Consultant::whereIn('id', $ids)->get() as $c) {
            DB::transaction(function () use ($c, $upTo, $min, $by, &$out, &$total): void {
                $balance = $this->openBalance($c->id, $upTo->code);
                $sit = $this->situation($c, $balance, $min);
                if ($sit['state'] !== self::READY) {
                    $out[$sit['state']]++;

                    return;
                }
                $year = (int) CarbonImmutable::now('Europe/Rome')->format('Y');
                $f = FiscalCalculator::compute($c->fiscal_regime ?? 'occasional', $balance, FiscalCalculator::ytdGross($c, $year));
                $payout = Payout::create([
                    'consultant_id' => $c->id, 'period_id' => $upTo->id, 'amount' => $f['net'], 'gross_amount' => (string) $balance, 'withholding_amount' => $f['withholding'],
                    'inps_amount' => $f['inps_worker'], 'fiscal_year' => $year, 'iban' => $c->iban,
                    'iban_holder' => $c->iban_holder, 'bic' => $c->bic, 'status' => Payout::TO_PAY, 'prepared_by' => $by,
                ]);
                Settlement::where('consultant_id', $c->id)->where('status', 'open')->whereNull('payout_id')
                    ->whereHas('period', fn ($p) => $p->where('code', '<=', $upTo->code))->update(['payout_id' => $payout->id]);
                $out['created']++;
                $total = $total->plus($balance);
            });
        }
        $out['amount'] = (string) Num::money($total);
        $upTo->update(['payouts_prepared_at' => now()]);
        AuditLog::record('payouts.prepared', "Pagamenti preparati fino a {$upTo->code}: {$out['created']} per {$out['amount']} €", $upTo, $out);

        return $out;
    }

    /**
     * Preparazione automatica (modalità `monthly_auto`): per ogni mese chiuso non ancora preparato, dal giorno
     * di pagamento del mese successivo (default il 15) crea i pagamenti «da pagare». I bonifici restano da eseguire in banca.
     *
     * @return array{periods:int, created:int, amount:string}
     */
    public function prepareDue(?DateTimeInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now())->setTimezone('Europe/Rome');
        $out = ['periods' => 0, 'created' => 0, 'amount' => '0.00'];
        $total = BigDecimal::zero();

        foreach (Period::where('status', Period::CLOSED)->whereNull('payouts_prepared_at')->orderBy('code')->get() as $period) {
            $plan = $period->planVersion->plan();
            if ($plan->payoutMode !== 'monthly_auto') {
                continue;
            }
            $due = CarbonImmutable::createFromFormat('!Y-m', $period->code, 'Europe/Rome')->addMonth()->day($plan->payoutDay);
            if ($now->lt($due)) {
                continue;
            }
            $r = $this->preparePayouts($period);
            $out['periods']++;
            $out['created'] += $r['created'];
            $total = $total->plus($r['amount']);
        }
        $out['amount'] = (string) Num::money($total);

        return $out;
    }

    /**
     * Mese storico già pagato fuori dal portale (es. Odoo): crea le liquidazioni e le segna subito «pagate»,
     * così non entrano mai in un pagamento nuovo. Il mese non si prepara più in automatico.
     */
    public function markHistorical(Period $period, string $note = 'Storico pagato fuori dal portale'): int
    {
        $n = $this->generateSettlements($period);
        Settlement::where('period_id', $period->id)->whereNull('payout_id')->where('status', 'open')->update(['status' => 'paid']);
        $period->update(['payouts_prepared_at' => now()]);
        // un mese già pagato altrove non apre un nuovo termine di reclamo
        CommissionStatement::where('period_id', $period->id)->where('status', CommissionStatement::PUBLISHED)
            ->update(['status' => CommissionStatement::ACCEPTED, 'accepted_at' => now()]);
        AuditLog::record('payouts.historical', "Mese {$period->code} segnato come storico già pagato", $period, ['liquidazioni' => $n], $note);

        return $n;
    }

    public function markPaid(Payout $payout, string $reference, DateTimeInterface $paidAt): Payout
    {
        if ($payout->status !== Payout::TO_PAY) {
            throw new PayoutException('Solo un pagamento «da pagare» si può segnare come pagato.');
        }
        DB::transaction(function () use ($payout, $reference, $paidAt): void {
            $payout->update(['status' => Payout::PAID, 'reference' => $reference, 'paid_at' => $paidAt, 'paid_by' => $this->actorLabel()]);
            Settlement::where('payout_id', $payout->id)->update(['status' => 'paid']);
            app(WithdrawalService::class)->onPaid($payout->refresh());
            app(ReceiptService::class)->issue($payout);
        });
        AuditLog::record('payout.paid', "Pagamento di {$payout->amount} € a {$payout->consultant->code} segnato come pagato", $payout, ['riferimento' => $reference, 'data' => $paidAt->format('Y-m-d')]);
        $this->notifyConsultant($payout->consultant, 'notify_payout_paid', new PayoutPaid($payout));

        return $payout->refresh();
    }

    /** Annulla un pagamento non ancora pagato: le liquidazioni tornano disponibili. */
    public function cancel(Payout $payout, string $reason): Payout
    {
        if ($payout->status !== Payout::TO_PAY) {
            throw new PayoutException('Solo un pagamento «da pagare» si può annullare.');
        }
        if (trim($reason) === '') {
            throw new PayoutException('Il motivo è obbligatorio.');
        }
        DB::transaction(function () use ($payout, $reason): void {
            Settlement::where('payout_id', $payout->id)->update(['payout_id' => null]);
            $payout->update(['status' => Payout::CANCELLED, 'cancel_reason' => $reason]);
            app(WithdrawalService::class)->onPayoutCancelled($payout);
        });
        AuditLog::record('payout.cancelled', "Pagamento a {$payout->consultant->code} annullato", $payout, [], $reason);

        return $payout->refresh();
    }

    /** Avvisa gli incaricati con compensi nel mese appena chiuso (mai i clienti). Se l'invio email è spento non parte nulla. */
    public function notifyStatementsReady(Period $period): int
    {
        if (! config('lamess.mail_enabled') || ! config('lamess.notify_statement_ready')) {
            return 0;
        }
        $n = 0;
        foreach (Settlement::where('period_id', $period->id)->with('consultant.user')->get() as $st) {
            if ($this->notifyConsultant($st->consultant, 'notify_statement_ready', new StatementReady($period->code))) {
                $n++;
            }
        }

        return $n;
    }

    /** Invio best-effort: un errore di posta non deve mai bloccare chiusure o pagamenti. */
    private function notifyConsultant(?Consultant $consultant, string $flag, Notification $notification): bool
    {
        if (! config('lamess.mail_enabled') || ! config("lamess.{$flag}") || $consultant?->user === null) {
            return false;
        }
        try {
            $consultant->user->notify($notification);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Avviso email all’incaricato non inviato', ['consulente' => $consultant->code, 'errore' => $e->getMessage()]);

            return false;
        }
    }

    private function actorLabel(): string
    {
        $u = Auth::user();

        return $u === null ? 'sistema' : (string) ($u->email ?? $u->name ?? 'utente');
    }
}
