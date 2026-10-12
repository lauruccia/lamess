<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Filament\Support\ConsultantList;
use Illuminate\Support\Facades\DB;

/**
 * Elenco degli incaricati in ordine di ramo (prima uno, poi tutta la sua discendenza), con il livello di ciascuno
 * per poterlo mostrare con un rientro. Il livello conta da chi si sceglie: l'incaricato scelto è 0, i suoi diretti 1,
 * i diretti dei diretti 2… Senza una scelta si parte da chi non ha sponsor (livello 0).
 */
final class LevelList
{
    /** Righe massime mostrate: oltre, l'elenco si tronca e lo si segnala. */
    public const LIMIT = 3000;

    /**
     * @param  int|null  $rootId  incaricato da cui partire; null = tutta la rete
     * @param  int|null  $maxLevel  ultimo livello da mostrare (null = tutti)
     * @param  string  $status  all | active | terminated | not_terminated: filtra solo cosa si vede, non il livello
     * @return array{rows: list<array{id:int,parent:?int,level:int,code:string,name:string,status:string,rank:?string,directs:int,clients:int,since:?string}>, total: int, truncated: bool}
     */
    public function rows(?int $rootId = null, ?int $maxLevel = null, string $status = 'all'): array
    {
        $people = DB::table('consultants')->get(['id', 'code', 'name', 'status', 'sponsor_id', 'since'])->keyBy('id');
        $children = [];
        foreach ($people as $p) {
            if ($p->sponsor_id !== null && $people->has($p->sponsor_id)) {
                $children[(int) $p->sponsor_id][] = (int) $p->id;
            }
        }
        foreach ($children as &$list) {
            usort($list, static fn (int $a, int $b): int => strcasecmp((string) $people[$a]->name, (string) $people[$b]->name) ?: $a <=> $b);
        }
        unset($list);

        if ($rootId !== null) {
            $starts = $people->has($rootId) ? [$rootId] : [];
        } else {
            $starts = $people->filter(static fn ($p): bool => $p->sponsor_id === null || ! $people->has($p->sponsor_id))->sortBy(static fn ($p): string => mb_strtolower((string) $p->name))->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        }

        $ranks = $this->ranks();
        $clients = DB::table('customers')->whereNotNull('assigned_consultant_id')->groupBy('assigned_consultant_id')
            ->selectRaw('assigned_consultant_id as id, count(*) as n')->pluck('n', 'id');

        $rows = [];
        $seen = [];
        $total = 0;
        $stack = [];
        foreach (array_reverse($starts) as $id) {
            $stack[] = [$id, 0, null];
        }
        while ($stack !== []) {
            [$id, $level, $shownParent] = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $p = $people[$id];
            $childParent = $shownParent;
            if ($this->passes((string) $p->status, $status)) {
                $childParent = $id;
                $total++;
                if (count($rows) < self::LIMIT) {
                    $rows[] = [
                        'id' => $id, 'parent' => $shownParent, 'level' => $level, 'code' => (string) $p->code, 'name' => (string) $p->name, 'status' => (string) $p->status,
                        'rank' => $ranks[$id] ?? null, 'directs' => count($children[$id] ?? []), 'clients' => (int) ($clients[$id] ?? 0),
                        'since' => $p->since ? substr((string) $p->since, 0, 10) : null,
                    ];
                }
            }
            if ($maxLevel !== null && $level >= $maxLevel) {
                continue;
            }
            foreach (array_reverse($children[$id] ?? []) as $child) {
                $stack[] = [$child, $level + 1, $childParent];
            }
        }

        return ['rows' => $rows, 'total' => $total, 'truncated' => $total > count($rows)];
    }

    private function passes(string $consultantStatus, string $filter): bool
    {
        return match ($filter) {
            'active' => $consultantStatus === 'active',
            'terminated' => $consultantStatus === 'terminated',
            'not_terminated' => $consultantStatus !== 'terminated',
            default => true,
        };
    }

    /** @return array<int, string> incaricato => nome della qualifica dall'ultimo calcolo (come l'elenco incaricati) */
    private function ranks(): array
    {
        $list = app(ConsultantList::class);
        $run = $list->runId();
        if ($run === null) {
            return [];
        }

        return DB::table('consultant_period_stats')->where('run_id', $run)->pluck('effective_rank', 'consultant_id')
            ->map(static fn ($code): string => $list->rankName((string) $code))->all();
    }
}
