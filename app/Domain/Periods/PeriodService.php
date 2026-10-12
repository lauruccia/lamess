<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Calc\ImpactReport;
use App\Domain\Engine\Calc\PeriodCalculator;
use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\PeriodInput;
use App\Domain\Engine\Model\PeriodResult;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use App\Domain\Payouts\PayoutService;
use App\Domain\Payouts\StatementLifecycle;
use App\Domain\Payouts\StructureBonusService;
use App\Domain\Wallet\WalletException;
use App\Domain\Wallet\WalletService;
use App\Models\CalculationRun;
use App\Models\CommissionStatement;
use App\Models\CompPlanVersion;
use App\Models\Payout;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\RankAchievement;
use App\Models\RankAward;
use App\Models\RankBonusInstallment;
use App\Models\Settlement;
use DateTimeZone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ciclo di vita del periodo: apertura → calcolo (live/ufficiale) → revisione → chiusura.
 * Il motore resta puro: qui si legge lo snapshot, si lancia il calcolo e si salvano i risultati.
 */
final class PeriodService
{
    public function open(string $code, CompPlanVersion $plan): Period
    {
        if (! in_array($plan->status, [CompPlanVersion::ACTIVE, CompPlanVersion::SCHEDULED], true)) {
            throw new PeriodStateException('Il periodo si apre solo con una versione di piano attiva.');
        }
        if (Period::where('code', $code)->exists()) {
            throw new PeriodStateException("Il periodo {$code} esiste già.");
        }
        $key = PeriodKey::parse($code);
        $utc = new DateTimeZone('UTC');

        return Period::create([
            'code' => $key->code(), 'starts_at' => $key->start()->setTimezone($utc), 'ends_at' => $key->end()->setTimezone($utc),
            'status' => Period::OPEN, 'comp_plan_version_id' => $plan->id,
        ]);
    }

    /** Calcolo su periodo aperto o in revisione. type: live (stima) | official (runtime del mese). */
    public function calculate(Period $period, string $type = 'live', bool $syncRanks = false): CalculationRun
    {
        $this->assertNotClosed($period);

        return $this->locked($period, function () use ($period, $type, $syncRanks): CalculationRun {
            $previous = $period->status;
            if ($type === 'official') {
                $period->update(['status' => Period::CALCULATING]);
            }
            $run = CalculationRun::create([
                'period_id' => $period->id, 'type' => $type, 'comp_plan_version_id' => $period->comp_plan_version_id,
                'input_hash' => '', 'status' => 'running', 'started_at' => now(),
            ]);
            try {
                $input = SnapshotBuilder::build($period->refresh());
                $result = PeriodCalculator::calculate($input);
                DB::transaction(function () use ($period, $run, $result, $type, $previous, $syncRanks): void {
                    CalculationRun::where('period_id', $period->id)->where('type', $type)->where('id', '!=', $run->id)
                        ->where('id', '!=', $period->official_run_id ?? 0)->delete();
                    $this->persist($period, $run, $result);
                    if ($syncRanks) {
                        $this->syncCalculatedRanks($period, $result);
                    }
                    $run->update([
                        'input_hash' => $result->inputHash, 'status' => 'completed', 'finished_at' => now(),
                        'totals' => $this->totals($result), 'warnings' => $result->warnings, 'decisions' => $result->decisionsUsed,
                    ]);
                    $period->update(['status' => $type === 'official' ? Period::REVIEW : $previous]);
                });
            } catch (Throwable $e) {
                $run->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
                $period->update(['status' => $previous]);
                throw $e;
            }

            return $run->refresh();
        });
    }

    /**
     * Simulazione/impatto (RF-062): ricalcola il periodo con un altro piano e confronta con quello in uso.
     * Nessuna scrittura nel database.
     *
     * @return array<string,mixed>
     */
    public function simulate(Period $period, CompPlanVersion $alt): array
    {
        $input = SnapshotBuilder::build($period);
        $base = PeriodCalculator::calculate($input);
        $simulated = PeriodCalculator::calculate(new PeriodInput($input->period, $alt->plan(), $input->nodes, $input->movements, $input->prior));

        return ImpactReport::compare($base, $simulated);
    }

    /** Chiusura: checklist bloccante, blocco delle righe, qualifiche e premi definitivi. */
    public function close(Period $period): Period
    {
        $this->assertNotClosed($period);

        return $this->locked($period, function () use ($period): Period {
            $run = CalculationRun::where('period_id', $period->id)->where('type', 'official')->where('status', 'completed')
                ->latest('id')->first();
            if ($run === null) {
                throw new PeriodStateException('Esegui prima il runtime ufficiale del mese.');
            }
            $pending = PvMovement::where('period_id', $period->id)->where('status', 'pending_payment')->count();
            if ($pending > 0) {
                throw new PeriodStateException("Ci sono {$pending} movimenti PV in attesa di pagamento: confermali o annullali prima di chiudere.");
            }
            $input = SnapshotBuilder::build($period);
            $result = PeriodCalculator::calculate($input);
            if ($result->inputHash !== $run->input_hash) {
                throw new PeriodStateException('I dati sono cambiati dopo l\'ultimo runtime: rilancia il runtime ufficiale.');
            }

            app(StructureBonusService::class)->syncFromResult($period, $result);

            DB::transaction(function () use ($period, $run, $result): void {
                CommissionLineLocker::lock($run);
                $this->persistRankState($period, $result);
                $period->update(['status' => Period::CLOSED, 'closed_at' => now(), 'official_run_id' => $run->id]);
                app(PayoutService::class)->generateSettlements($period->refresh());
            });

            $period->refresh();
            app(StatementLifecycle::class)->publish($period);
            app(WalletService::class)->creditPeriod($period);
            app(PayoutService::class)->notifyStatementsReady($period);

            return $period;
        });
    }

    /**
     * Riapertura di un mese chiuso: solo super_admin, con motivo. Si può riaprire solo l'ultimo mese chiuso
     * (lo stato iniziale dei mesi successivi si legge da quello) e solo se nessun suo importo risulta già pagato.
     * Le righe tornano modificabili, qualifiche e premi scritti alla chiusura vengono annullati, le liquidazioni cancellate.
     */
    public function reopen(Period $period, string $reason): Period
    {
        if (! (Auth::user()?->isSuperAdmin() ?? false)) {
            throw new PeriodStateException('Solo il super_admin può riaprire un mese chiuso.');
        }
        if (trim($reason) === '') {
            throw new PeriodStateException('Il motivo della riapertura è obbligatorio.');
        }
        if (! $period->refresh()->isClosed()) {
            throw new PeriodStateException("Il periodo {$period->code} non è chiuso.");
        }
        $later = Period::where('code', '>', $period->code)->where('status', Period::CLOSED)->orderBy('code')->first();
        if ($later !== null) {
            throw new PeriodStateException("Prima va riaperto {$later->code}: si riapre un mese alla volta, dal più recente.");
        }
        $paid = Settlement::where('period_id', $period->id)->where('status', 'paid')->count();
        if ($paid > 0) {
            throw new PeriodStateException('Alcune liquidazioni di questo mese risultano già pagate: non si può riaprire. Correggi con una rettifica nel mese aperto.');
        }

        try {
            app(WalletService::class)->assertReversible($period);
        } catch (WalletException $e) {
            throw new PeriodStateException($e->getMessage());
        }

        return $this->locked($period, function () use ($period, $reason): Period {
            DB::transaction(function () use ($period): void {
                app(WalletService::class)->reversePeriod($period);
                $payoutIds = Settlement::where('period_id', $period->id)->whereNotNull('payout_id')->pluck('payout_id')->unique();
                foreach (Payout::whereIn('id', $payoutIds)->where('status', Payout::TO_PAY)->get() as $payout) {
                    Settlement::where('payout_id', $payout->id)->update(['payout_id' => null]);
                    $payout->update(['status' => Payout::CANCELLED, 'cancel_reason' => "Riapertura del mese {$period->code}"]);
                }
                Settlement::where('period_id', $period->id)->delete();
                CommissionStatement::where('period_id', $period->id)->delete();

                DB::table('commission_lines')->where('period_id', $period->id)->where('status', 'locked')->update(['status' => 'draft', 'updated_at' => now()]);
                RankAchievement::where('period_id', $period->id)->where('source', 'calculated')->delete();
                foreach (RankAward::where('achieved_period', $period->code)->get() as $award) {
                    $award->installments()->delete();
                    $award->delete();
                }
                RankBonusInstallment::where('due_period', $period->code)->whereIn('status', ['paid', 'held'])->update(['status' => 'due']);

                $period->update(['status' => Period::REVIEW, 'closed_at' => null, 'payouts_prepared_at' => null, 'official_run_id' => null]);
            });
            AuditLog::record('period.reopened', "Mese {$period->code} riaperto", $period, [], $reason);

            return $period->refresh();
        });
    }

    private function assertNotClosed(Period $period): void
    {
        if ($period->refresh()->isClosed()) {
            throw new PeriodStateException("Il periodo {$period->code} è chiuso: correzioni solo come rettifiche nel periodo aperto.");
        }
    }

    /** @template T @param callable():T $fn @return T */
    private function locked(Period $period, callable $fn): mixed
    {
        $lock = Cache::lock("period:{$period->id}", 600);
        if (! $lock->get()) {
            throw new PeriodStateException("Un altro calcolo è già in corso sul periodo {$period->code}.");
        }
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /** @return array<string,string> */
    private function totals(PeriodResult $r): array
    {
        $out = [];
        foreach (Line::BONUSES as $b) {
            if ($b === 'structure') {
                continue; // fuori dal mese: registro settimanale
            }
            $out[$b] = (string) Num::money($r->total($b));
        }
        $out['total'] = (string) Num::money($r->total()->minus($r->total('structure')));
        $out['held'] = (string) Num::money($r->total(null, ['held'])->minus($r->total('structure', ['held'])));

        return $out;
    }

    private function persist(Period $period, CalculationRun $run, PeriodResult $r): void
    {
        $now = now();
        $rows = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === 'structure') {
                continue; // il Bonus di Struttura ha il suo registro settimanale (StructureBonusService)
            }
            $rows[] = [
                'run_id' => $run->id, 'period_id' => $period->id, 'beneficiary_id' => (int) $l->beneficiaryId, 'line_key' => $l->key,
                'bonus_type' => $l->bonus, 'base_amount' => (string) Num::money($l->base), 'pct' => $l->rate === null ? null : (string) $l->rate,
                'amount' => (string) Num::money($l->amount), 'source_movement_id' => $l->sourceMovementId === null ? null : (int) $l->sourceMovementId,
                'source_consultant_id' => $l->sourcePartnerId === null ? null : (int) $l->sourcePartnerId, 'level' => $l->level,
                'engine_status' => $l->status, 'reason' => $l->reason, 'explanation' => json_encode($l->explanation, JSON_UNESCAPED_UNICODE),
                'decisions' => json_encode($l->decisions), 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('commission_lines')->insert($chunk);
        }
        $stats = [];
        foreach ($r->stats as $id => $s) {
            $stats[] = [
                'run_id' => $run->id, 'period_id' => $period->id, 'consultant_id' => (int) $id,
                'personal_pvf' => (string) $s->personalPvf, 'personal_pvc' => (string) $s->personalPvc, 'personal_cv' => (string) $s->personalCv,
                'group_pv' => (string) $s->groupPv, 'activity_points' => (string) $s->activityPoints,
                'pr_opening' => (string) $s->personalPrOpening, 'pr_closing' => (string) $s->personalPrClosing,
                'group_pr_closing' => (string) $s->groupPrClosing, 'is_active' => $s->active, 'active_reason' => $s->activeReason,
                'first_activity_period' => $s->firstActivityPeriod, 'achieved_rank' => $s->achievedRank, 'effective_rank' => $s->effectiveRank,
                'indirect_status' => $s->indirectStatus, 'matching_status' => $s->matchingStatus,
                'next_rank' => json_encode($s->nextRank, JSON_UNESCAPED_UNICODE), 'lines_pr' => json_encode($s->linesPr),
            ];
        }
        foreach (array_chunk($stats, 500) as $chunk) {
            DB::table('consultant_period_stats')->insert($chunk);
        }
    }

    /**
     * Allinea le qualifiche ufficiali «calcolate» del mese al risultato del calcolo: scrive quelle raggiunte e toglie
     * quelle che non risultano più (ad esempio dopo un reso). Non tocca le qualifiche forzate né quelle di altri mesi.
     * Usato dal ricalcolo notturno e dalla chiusura.
     */
    private function syncCalculatedRanks(Period $period, PeriodResult $r): void
    {
        $keep = [];
        foreach ($r->achievements as $a) {
            $keep[(int) $a->partnerId.'|'.$a->rankCode] = true;
            RankAchievement::updateOrCreate(
                ['consultant_id' => (int) $a->partnerId, 'rank_code' => $a->rankCode],
                ['achieved_at' => $a->achievedAt, 'effective_from' => $a->effectiveFrom, 'period_id' => $period->id, 'source' => 'calculated', 'detail' => $a->detail],
            );
        }
        foreach (RankAchievement::where('period_id', $period->id)->where('source', 'calculated')->get() as $old) {
            if (! isset($keep[$old->consultant_id.'|'.$old->rank_code])) {
                $old->delete();
            }
        }
    }

    /** Qualifiche, premi Rank Bonus e rate: scritti alla chiusura (le qualifiche anche dal ricalcolo notturno del piano Incaricati). */
    private function persistRankState(Period $period, PeriodResult $r): void
    {
        $this->syncCalculatedRanks($period, $r);

        // rate in scadenza in questo periodo: pagate o trattenute secondo l'esito del calcolo
        $byKey = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === 'rank_installment') {
                $byKey[$l->key] = $l->status;
            }
        }
        foreach (RankBonusInstallment::with('award')->where('status', 'due')->where('due_period', $period->code)->get() as $i) {
            $st = $byKey["rank_installment:{$i->award->award_key}:{$i->number}"] ?? null;
            if ($st !== null) {
                $i->update(['status' => $st === 'accrued' ? 'paid' : 'held']);
            }
        }

        foreach ($r->rankAwards as $a) {
            $award = RankAward::updateOrCreate(
                ['award_key' => $a->awardKey],
                ['consultant_id' => (int) $a->partnerId, 'rank_code' => $a->rankCode, 'amount' => (string) Num::money($a->amount), 'status' => $a->status, 'reason' => $a->reason]
                + (RankAward::where('award_key', $a->awardKey)->exists() ? [] : ['achieved_period' => $period->code]),
            );
            if ($a->installments !== [] && $award->installments()->doesntExist()) {
                $due = PeriodKey::parse($a->firstInstallmentPeriod);
                foreach ($a->installments as $n => $amount) {
                    $paidNow = $n === 0 && $due->equals(PeriodKey::parse($period->code));
                    $award->installments()->create([
                        'number' => $n + 1, 'of' => count($a->installments), 'amount' => (string) $amount,
                        'due_period' => $due->code(), 'status' => $paidNow ? 'paid' : 'due',
                    ]);
                    $due = $due->next();
                }
            }
        }
    }
}
