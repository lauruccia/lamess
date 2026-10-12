<?php

declare(strict_types=1);

namespace App\Domain\Plan;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Calc\PeriodCalculator;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\RankAchievement;

/**
 * Confronto tra la qualifica del sistema precedente e quella che risulterebbe con i requisiti del piano Incaricati.
 * Solo lettura: non scrive qualifiche né compensi. Il calcolo usa i dati del periodo scelto, ripartendo da zero.
 */
final class RankComparison
{
    /** Piano Incaricati più recente (bozza, programmato o attivo). */
    public function incaricatiPlan(): ?CompPlanVersion
    {
        return CompPlanVersion::query()->orderByDesc('id')->get()->first(fn (CompPlanVersion $v) => ($v->data['settings']['engine'] ?? '') === 'kosmos_v1');
    }

    /**
     * Congela come «qualifica precedente» la qualifica più alta oggi registrata, solo per chi non ce l'ha ancora.
     *
     * @return int numero di incaricati aggiornati
     */
    public function freezePrevious(): int
    {
        $plan = CompPlanVersion::query()->whereNotNull('activated_at')->orderByDesc('id')->get()
            ->first(fn (CompPlanVersion $v) => ($v->data['settings']['engine'] ?? 'multilevel_v3') !== 'kosmos_v1')?->plan();
        $seq = [];
        foreach ($plan?->ranks ?? [] as $r) {
            $seq[$r->code] = $r->sequence;
        }
        $best = [];
        foreach (RankAchievement::query()->get(['consultant_id', 'rank_code']) as $a) {
            $s = $seq[$a->rank_code] ?? -1;
            if ($s > ($best[$a->consultant_id][1] ?? -1)) {
                $best[$a->consultant_id] = [$a->rank_code, $s];
            }
        }
        $n = 0;
        foreach (Consultant::query()->whereNull('previous_rank_code')->get() as $c) {
            $c->update(['previous_rank_code' => $best[$c->id][0] ?? ($plan?->entryRank()->code ?? 'CONSULTANT')]);
            $n++;
        }
        AuditLog::record('plan.previous_ranks_frozen', "Qualifiche del sistema precedente congelate per {$n} incaricati", null, ['incaricati' => $n]);

        return $n;
    }

    /**
     * @return list<array{id:int,code:string,name:string,previous:?string,new_achieved:string,new_effective:string,clients:int,missing:string,requirements:string,requirements_effective:string,next_rank:?string,next_requirements:string,changed:bool}>
     */
    public function compare(Period $period, PlanConfig $plan, bool $cumulativeClients = true): array
    {
        // Nel confronto i clienti si contano per default in modo cumulativo (chi ha acquistato almeno una volta fino a fine mese):
        // con il solo mese scelto un incaricato che quel mese non vende risulterebbe a 0 clienti, anche se ne ha molti.
        if ($cumulativeClients) {
            $plan = $plan->withPolicies(['kosmos_client_count' => 'cumulative_buyers']);
        }
        $result = PeriodCalculator::calculate(SnapshotBuilder::build($period, $plan, freshCareer: true));
        $seq = [];
        foreach ($plan->ranks as $r) {
            $seq[$r->code] = $r->sequence;
        }
        $rows = [];
        foreach (Consultant::query()->whereNotNull('previous_rank_code')->orderBy('code')->get() as $c) {
            $st = $result->stats[(string) $c->id] ?? null;
            if ($st === null) {
                continue;
            }
            $next = $st->nextRank ?? [];
            $missing = [];
            if (isset($next['clients_required']) && ! ($next['clients_ok'] ?? true)) {
                $missing[] = "clienti {$next['clients_counted']}/{$next['clients_required']}";
            }
            if (isset($next['pearl_first_level_required']) && ! ($next['pearl_first_level_ok'] ?? true)) {
                $missing[] = "Pearl 1° liv. {$next['pearl_first_level_counted']}/{$next['pearl_first_level_required']}";
            }
            if (! empty($next['structure_missing'])) {
                $missing[] = 'struttura: '.implode(', ', $next['structure_missing']);
            }
            $prev = $c->previous_rank_code;
            $rows[] = [
                'id' => $c->id, 'code' => (string) $c->code, 'name' => (string) $c->name, 'previous' => $prev,
                'new_achieved' => $st->achievedRank, 'new_effective' => $st->effectiveRank,
                'clients' => (int) ($next['clients_counted'] ?? 0), 'missing' => implode(' · ', $missing),
                'requirements' => self::requirementsText($plan, (string) $st->achievedRank),
                'next_rank' => ($next['rank'] ?? null) !== null ? (string) $next['rank'] : null,
                'next_requirements' => ($next['rank'] ?? null) !== null ? self::requirementsText($plan, (string) $next['rank']) : '',
                'requirements_effective' => self::requirementsText($plan, (string) $st->effectiveRank),
                'changed' => ($seq[$prev] ?? -1) !== ($seq[$st->achievedRank] ?? -2),
            ];
        }

        return $rows;
    }

    /** Requisiti di una qualifica del piano, in italiano, per il suggerimento al passaggio del mouse. */
    private static function requirementsText(PlanConfig $plan, string $code): string
    {
        if (! $plan->hasCode($code)) {
            return $code;
        }
        $r = $plan->rank($code);
        $parts = [];
        if ($r->minClients > 0 && $r->requires('clients')) {
            $parts[] = "{$r->minClients} clienti personali";
        }
        if ($r->minPearlFirstLevel > 0 && $r->requires('pearl')) {
            $parts[] = "{$r->minPearlFirstLevel} Pearl al 1° livello";
        }
        if ($r->requires('structure')) {
            foreach ($r->structure as $req) {
                $parts[] = "{$req->count} linee con almeno {$req->rankCode}";
            }
        }
        if ($r->minColumns > 0 && $r->minColumnClients > 0 && $r->requires('columns')) {
            $parts[] = "{$r->minColumns} colonne da almeno {$r->minColumnClients} clienti";
        }

        return "Requisiti {$r->name}: ".($parts === [] ? 'nessuno (qualifica di ingresso)' : implode(' · ', $parts));
    }
}
