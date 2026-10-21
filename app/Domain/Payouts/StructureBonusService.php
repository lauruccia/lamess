<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Calc\PeriodCalculator;
use App\Domain\Engine\Model\PeriodResult;
use App\Domain\Engine\Support\Num;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\Payout;
use App\Models\Period;
use App\Models\StructureBonus;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bonus di Struttura del piano Incaricati: fuori dal calcolo mensile.
 *
 * Ogni settimana (giorno e ora scelti dall'admin) i bonus maturati vengono calcolati e scritti nel registro. Un bonus
 * ha una chiave unica: finché è «calcolato» si aggiorna a ogni calcolo, dopo la conferma dell'admin (con chi e quando)
 * non cambia più e si paga una sola volta. Alla chiusura del mese il registro viene aggiornato un'ultima volta,
 * così nessun bonus maturato negli ultimi giorni si perde.
 */
final class StructureBonusService
{
    public const CACHE_KEY = 'lamess.structure_bonus.last';

    /** Calcola e registra i bonus dei mesi non ancora chiusi. @return array{new:int, updated:int} */
    public function syncOpenPeriods(): array
    {
        $new = $updated = 0;
        foreach (Period::where('status', '!=', Period::CLOSED)->orderBy('code')->get() as $period) {
            if ($period->planVersion->plan()->kosmos === null) {
                continue;
            }
            $result = PeriodCalculator::calculate(SnapshotBuilder::build($period));
            [$n, $u] = $this->syncFromResult($period, $result);
            $new += $n;
            $updated += $u;
        }
        Cache::forever(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'new' => $new, 'updated' => $updated]);
        if ($new + $updated > 0) {
            AuditLog::record('structure_bonus.calculated', "Bonus di Struttura calcolati: {$new} nuovi, {$updated} aggiornati", null, ['nuovi' => $new, 'aggiornati' => $updated]);
        }

        return ['new' => $new, 'updated' => $updated];
    }

    /** @return array{0:int, 1:int} nuovi, aggiornati */
    public function syncFromResult(Period $period, PeriodResult $result): array
    {
        $new = $updated = 0;
        DB::transaction(function () use ($period, $result, &$new, &$updated): void {
            foreach ($result->lines as $l) {
                if ($l->bonus !== 'structure') {
                    continue;
                }
                $amount = (string) Num::money($l->amount);
                $row = StructureBonus::where('bonus_key', $l->key)->first();
                if ($row === null) {
                    StructureBonus::create([
                        'bonus_key' => $l->key, 'beneficiary_id' => (int) $l->beneficiaryId,
                        'source_consultant_id' => $l->sourcePartnerId === null ? null : (int) $l->sourcePartnerId,
                        'period_id' => $period->id, 'amount' => $amount, 'latest_amount' => $amount, 'engine_status' => $l->status,
                        'status' => StructureBonus::CALCULATED, 'explanation' => $l->explanation, 'calculated_at' => now(),
                    ]);
                    $new++;
                } elseif ($row->status === StructureBonus::CALCULATED) {
                    if ((string) $row->amount !== $amount || $row->engine_status !== $l->status) {
                        $row->update(['amount' => $amount, 'latest_amount' => $amount, 'engine_status' => $l->status, 'explanation' => $l->explanation, 'calculated_at' => now()]);
                        $updated++;
                    }
                } elseif ($row->status !== StructureBonus::CANCELLED && (string) $row->latest_amount !== $amount) {
                    // già confermato o pagato: l'importo non si tocca, ma la differenza resta visibile all'admin
                    $row->update(['latest_amount' => $amount, 'note' => 'Il ricalcolo darebbe '.$amount.' €: importo già confermato, non modificato.']);
                }
            }
        });

        return [$new, $updated];
    }

    /**
     * Conferma i bonus scelti: da quel momento l'importo è definitivo. Salta quelli trattenuti o a zero.
     *
     * @param  list<int>  $ids
     */
    public function confirm(array $ids): int
    {
        $n = 0;
        foreach (StructureBonus::whereIn('id', $ids)->where('status', StructureBonus::CALCULATED)->where('engine_status', 'accrued')->get() as $b) {
            if (Num::money((string) $b->amount)->isZero()) {
                continue;
            }
            $b->update(['status' => StructureBonus::CONFIRMED, 'confirmed_at' => now(), 'confirmed_by' => Auth::id()]);
            $n++;
            $this->creditToTasca($b);
        }
        if ($n > 0) {
            AuditLog::record('structure_bonus.confirmed', "Confermati {$n} Bonus di Struttura", null, ['bonus' => $n]);
        }

        return $n;
    }

    /**
     * Segna come pagati i bonus confermati. Per ogni incaricato i bonus scelti diventano UN pagamento (come gli altri pagamenti):
     * ritenuta, INPS e ricevuta seguono le stesse regole. Nessun denaro si muove da qui: il bonifico si fa in banca.
     * Restano «confermati», con il motivo, i bonus di chi ha un blocco (KYC, IBAN, pagamenti sospesi) o resta sotto la soglia minima.
     *
     * @param  list<int>  $ids
     * @return array{paid:int, payouts:int, skipped:array<string, list<string>>}
     */
    public function markPaid(array $ids, string $reference): array
    {
        $out = ['paid' => 0, 'payouts' => 0, 'skipped' => []];
        $by = StructureBonus::whereIn('id', $ids)->where('status', StructureBonus::CONFIRMED)->whereNull('payout_id')
            ->with(['beneficiary', 'period.planVersion'])->get()->groupBy('beneficiary_id');
        $label = Auth::user() === null ? 'sistema' : (string) (Auth::user()->email ?? Auth::user()->name ?? 'utente');
        foreach ($by as $bonuses) {
            $c = $bonuses->first()->beneficiary;
            $gross = BigDecimal::zero();
            foreach ($bonuses as $b) {
                $gross = $gross->plus((string) $b->amount);
            }
            $gross = Num::money($gross);
            $period = $bonuses->sortByDesc('period_id')->first()->period ?? Period::orderByDesc('code')->first();
            $min = $period->planVersion->plan()->minPayout;
            $sit = app(PayoutService::class)->situation($c, $gross, $min);
            if ($sit['state'] !== PayoutService::READY) {
                $out['skipped'][$c->code] = $sit['reasons'];

                continue;
            }
            DB::transaction(function () use ($bonuses, $c, $gross, $period, $reference, $label, &$out): void {
                $now = now();
                $year = (int) CarbonImmutable::now('Europe/Rome')->format('Y');
                $f = FiscalCalculator::compute($c->fiscal_regime ?? 'occasional', $gross, FiscalCalculator::ytdGross($c, $year));
                $payout = Payout::create([
                    'consultant_id' => $c->id, 'period_id' => $period->id, 'amount' => $f['net'], 'gross_amount' => (string) $gross,
                    'withholding_amount' => $f['withholding'], 'fee_amount' => '0.00', 'inps_amount' => $f['inps_worker'], 'fiscal_year' => $year,
                    'iban' => $c->iban, 'iban_holder' => $c->iban_holder, 'status' => Payout::PAID, 'reference' => $reference,
                    'paid_at' => $now, 'prepared_by' => $label, 'paid_by' => $label,
                ]);
                foreach ($bonuses as $b) {
                    $b->update(['status' => StructureBonus::PAID, 'paid_at' => $now, 'paid_by' => Auth::id(), 'payment_reference' => $reference, 'payout_id' => $payout->id]);
                }
                app(ReceiptService::class)->issue($payout);
                $out['paid'] += $bonuses->count();
                $out['payouts']++;
            });
        }
        if ($out['paid'] > 0) {
            AuditLog::record('structure_bonus.paid', "Pagati {$out['paid']} Bonus di Struttura in {$out['payouts']} pagamenti", null, ['bonus' => $out['paid'], 'pagamenti' => $out['payouts'], 'riferimento' => $reference]);
        }

        return $out;
    }

    /** True se i compensi vanno nella Tasca (pagamento «su richiesta»): i bonus confermati si accreditano lì invece di essere pagati a parte. */
    public static function usesTasca(): bool
    {
        return \App\Domain\Wallet\WithdrawalService::enabled();
    }

    /**
     * Con la Tasca attiva il bonus confermato diventa subito un accredito (una sola volta): il prelievo, con ritenuta e INPS, lo chiede poi l'incaricato.
     * Il bonus risulta «pagato» con riferimento «Tasca»; blocchi (KYC, IBAN, sospensione) e soglia valgono al momento del prelievo.
     */
    private function creditToTasca(StructureBonus $b): void
    {
        if (! self::usesTasca() || $b->wallet_credited_at !== null) {
            return;
        }
        $amount = Num::money((string) $b->amount);
        $b->loadMissing(['beneficiary', 'source']);
        DB::transaction(function () use ($b, $amount): void {
            app(\App\Domain\Wallet\WalletService::class)->post(
                $b->beneficiary, 'structure_bonus', $amount,
                'Bonus di Struttura'.($b->source ? ' — '.$b->source->name.' ['.$b->source->code.']' : ''),
                $b->period_id ? ['period_id' => $b->period_id] : [],
            );
            $now = now();
            $b->update(['status' => StructureBonus::PAID, 'paid_at' => $now, 'paid_by' => Auth::id(), 'payment_reference' => 'Tasca', 'wallet_credited_at' => $now]);
        });
    }

    /** @param list<int> $ids */
    public function cancel(array $ids, string $reason): int
    {
        $n = StructureBonus::whereIn('id', $ids)->whereIn('status', [StructureBonus::CALCULATED, StructureBonus::CONFIRMED])
            ->update(['status' => StructureBonus::CANCELLED, 'note' => $reason, 'updated_at' => now()]);
        if ($n > 0) {
            AuditLog::record('structure_bonus.cancelled', "Annullati {$n} Bonus di Struttura", null, ['bonus' => $n], $reason);
        }

        return $n;
    }
}
