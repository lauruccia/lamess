<?php

declare(strict_types=1);

namespace App\Domain\Career;

use App\Domain\Engine\Plan\PlanConfig;
use App\Models\CalculationRun;
use App\Models\Consultant;
use App\Models\ConsultantPeriodStat;
use App\Models\Period;
use Illuminate\Support\Facades\DB;

/**
 * Dati della pagina «Il mio ramo» per il piano Incaricati: per ogni persona della rete qualifica, clienti, diretti,
 * Pearl al 1° livello, venduto del mese; poi i totali per livello e per colonna (un diretto con tutta la sua discendenza).
 * Visibilità (GDPR): solo nome, codice, qualifica, stato e numeri aggregati; nessun recapito.
 */
final class NetworkOverview
{
    /** @return array<string, mixed> */
    public function for(Consultant $me): array
    {
        $period = Period::query()->orderByDesc('code')->first();
        $plan = $period?->planVersion->plan();
        $links = DB::table('network_paths')->where('ancestor_id', $me->id)->where('depth', '>', 0)->pluck('depth', 'descendant_id')->all();
        $ids = array_map('intval', array_keys($links));
        $nodes = Consultant::query()->whereIn('id', $ids)->get(['id', 'code', 'name', 'sponsor_id', 'status', 'since'])->keyBy('id');

        $stats = $period === null ? [] : $this->stats($period, array_merge($ids, [$me->id]), $plan);
        $volume = $period === null ? [] : $this->volume($period, array_merge($ids, [$me->id]), $plan);

        $children = [];
        foreach ($nodes as $n) {
            $children[(int) $n->sponsor_id][] = (int) $n->id;
        }
        foreach ($children as &$list) {
            usort($list, static fn ($a, $b) => strcmp((string) $nodes[$a]->name, (string) $nodes[$b]->name));
        }
        unset($list);

        // Totali per sotto-albero (persone, clienti, venduto) e radice della colonna.
        $sub = [];
        $walk = function (int $id) use (&$walk, &$sub, $children, $stats, $volume): array {
            $persons = 1;
            $clients = (int) ($stats[$id]['clients'] ?? 0);
            $eur = (float) ($volume[$id] ?? 0);
            $best = (int) ($stats[$id]['seq'] ?? 0);
            foreach ($children[$id] ?? [] as $c) {
                [$p, $cl, $e, $b] = $walk($c);
                $persons += $p;
                $clients += $cl;
                $eur += $e;
                $best = max($best, $b);
            }
            $sub[$id] = ['persons' => $persons, 'clients' => $clients, 'eur' => $eur, 'best' => $best];

            return [$persons, $clients, $eur, $best];
        };
        foreach ($children[(int) $me->id] ?? [] as $root) {
            $walk($root);
        }

        // Per livello.
        $byLevel = [];
        foreach ($links as $id => $depth) {
            $id = (int) $id;
            $row = &$byLevel[(int) $depth];
            $row ??= ['level' => (int) $depth, 'persons' => 0, 'clients' => 0, 'eur' => 0.0, 'pearl' => 0, 'with_clients' => 0];
            $row['persons']++;
            $row['clients'] += (int) ($stats[$id]['clients'] ?? 0);
            $row['eur'] += (float) ($volume[$id] ?? 0);
            $row['pearl'] += ($stats[$id]['is_pearl'] ?? false) ? 1 : 0;
            $row['with_clients'] += ((int) ($stats[$id]['clients'] ?? 0)) > 0 ? 1 : 0;
            unset($row);
        }
        ksort($byLevel);

        // Per colonna.
        $columns = [];
        foreach ($children[(int) $me->id] ?? [] as $root) {
            $t = $sub[$root];
            $columns[] = [
                'id' => $root, 'name' => (string) $nodes[$root]->name, 'code' => (string) $nodes[$root]->code, 'rank' => $stats[$root]['rank'] ?? null,
                'persons' => $t['persons'], 'clients' => $t['clients'], 'eur' => $t['eur'], 'best_rank' => $plan !== null && $plan->hasSeq($t['best']) ? $plan->bySeq($t['best'])->name : null,
            ];
        }
        usort($columns, static fn ($a, $b) => $b['clients'] <=> $a['clients']);

        $tot = ['persons' => count($ids), 'clients' => 0, 'eur' => 0.0, 'pearl' => 0, 'new30' => 0];
        $limit = now()->subDays(30);
        foreach ($ids as $id) {
            $tot['clients'] += (int) ($stats[$id]['clients'] ?? 0);
            $tot['eur'] += (float) ($volume[$id] ?? 0);
            $tot['pearl'] += ($stats[$id]['is_pearl'] ?? false) ? 1 : 0;
            if ($nodes[$id]->since !== null && $nodes[$id]->since->greaterThanOrEqualTo($limit)) {
                $tot['new30']++;
            }
        }

        return [
            'me' => $me, 'period' => $period?->code, 'plan' => $plan, 'nodes' => $nodes, 'children' => $children, 'stats' => $stats, 'volume' => $volume, 'sub' => $sub,
            'levels' => $byLevel, 'columns' => $columns, 'total' => $tot, 'depth' => $links === [] ? 0 : (int) max($links),
            'direct' => count($children[(int) $me->id] ?? []), 'mine' => $stats[(int) $me->id] ?? null, 'mine_eur' => (float) ($volume[(int) $me->id] ?? 0),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function stats(Period $period, array $ids, ?PlanConfig $plan): array
    {
        $runId = $period->official_run_id ?? CalculationRun::query()->where('period_id', $period->id)->where('status', 'completed')->orderByDesc('id')->value('id');
        if ($runId === null || $plan === null) {
            return [];
        }
        $pearlSeq = $plan->kosmos !== null && $plan->hasCode($plan->kosmos->pearlRank) ? $plan->rank($plan->kosmos->pearlRank)->sequence : null;
        $out = [];
        foreach (ConsultantPeriodStat::query()->where('run_id', $runId)->whereIn('consultant_id', $ids)->get() as $s) {
            $next = (array) $s->next_rank;
            $rank = $plan->hasCode((string) $s->effective_rank) ? $plan->rank((string) $s->effective_rank) : null;
            $out[(int) $s->consultant_id] = [
                'rank' => $rank?->name, 'seq' => $rank?->sequence ?? 0, 'active' => (bool) $s->is_active, 'reason' => (string) $s->active_reason,
                'clients' => isset($next['personal_clients']) ? (int) $next['personal_clients'] : (isset($next['clients_counted']) ? (int) $next['clients_counted'] : 0),
                'pearl_first' => isset($next['pearl_first_level_now']) ? (int) $next['pearl_first_level_now'] : (isset($next['pearl_first_level_counted']) ? (int) $next['pearl_first_level_counted'] : 0),
                'is_pearl' => $pearlSeq !== null && $rank !== null && $rank->sequence >= $pearlSeq,
                'pvf' => $s->personal_pvf, 'pr' => $s->pr_closing, 'group_pv' => $s->group_pv, 'group_pr' => $s->group_pr_closing,
            ];
        }

        return $out;
    }

    /**
     * Venduto del mese ai clienti, in euro IVA inclusa (movimenti senza importo: punti × euro per punto del piano).
     *
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function volume(Period $period, array $ids, ?PlanConfig $plan): array
    {
        $perPv = $plan === null ? '1.5' : (string) $plan->commissionBasePerPv;

        return DB::table('pv_movements')->where('period_id', $period->id)->where('status', 'confirmed')->where('kind', 'pvf')->where('excluded_from_bonus', false)
            ->whereIn('consultant_id', $ids)->groupBy('consultant_id')
            ->selectRaw('consultant_id, sum(coalesce(amount, pv * ?)) as eur', [$perPv])->pluck('eur', 'consultant_id')
            ->map(static fn ($v) => (float) $v)->all();
    }
}
