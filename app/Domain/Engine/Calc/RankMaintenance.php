<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\RankDef;

/**
 * Mantenimento della qualifica nel piano Incaricati (politica kosmos_rank_maintenance = requirements):
 * l'incaricato scende alla qualifica più alta di cui rispetta ancora TUTTI i requisiti del mese
 * (clienti personali, Pearl al 1° livello, struttura di altri incaricati, colonne da N clienti).
 * Si procede dal basso verso l'alto: i requisiti di un incaricato dipendono solo dalla sua discendenza,
 * già ricalcolata, quindi un passaggio basta.
 */
final class RankMaintenance
{
    /**
     * @param  array<string, int>  $eff  qualifica efficace (sequenza) per incaricato, prima del mantenimento
     * @param  array<string, int>  $clients  clienti personali del mese
     * @param  array<string, bool>  $protected  incaricati la cui qualifica non si ribassa (protezione del piano o «Mantieni qualifica»)
     * @param  array<string, array<int, int>>  $helps  agenti virtuali regalati: incaricato => sequenza qualifica => quanti
     * @return array<string, int>
     */
    public static function apply(PlanConfig $plan, Tree $tree, array $eff, array $clients, array $protected, array $helps = []): array
    {
        $cfg = $plan->kosmos;
        if ($cfg === null) {
            return $eff;
        }
        $pearlSeq = $plan->rank($cfg->pearlRank)->sequence;
        /** @var array<string, int> $subClients */
        $subClients = [];
        /** @var array<string, int> $subMax */
        $subMax = [];
        $directOnly = $plan->policies->is('structure_depth', 'direct_only');

        foreach ($tree->bottomUp as $id) {
            $id = (string) $id;
            $subClients[$id] = ($clients[$id] ?? 0) + array_sum(array_map(static fn ($c) => $subClients[(string) $c] ?? 0, $tree->childrenOf($id)));
            if (! $tree->isMember($id)) {
                $eff[$id] = $eff[$id] ?? 0;
            } elseif (! ($protected[$id] ?? false)) {
                while ($eff[$id] > 0 && ! self::meets($plan, $tree, $plan->bySeq($eff[$id]), $id, $eff, $clients, $subClients, $subMax, $pearlSeq, $directOnly, $helps[$id] ?? [])) {
                    $eff[$id]--;
                }
            }
            $best = $tree->isMember($id) ? $eff[$id] : -1;
            foreach ($tree->childrenOf($id) as $c) {
                $best = max($best, $subMax[(string) $c] ?? -1);
            }
            $subMax[$id] = $best;
        }

        return $eff;
    }

    /**
     * @param  array<string, int>  $eff
     * @param  array<string, int>  $clients
     * @param  array<string, int>  $subClients
     * @param  array<string, int>  $subMax
     */
    private static function meets(PlanConfig $plan, Tree $tree, RankDef $rank, string $id, array $eff, array $clients, array $subClients, array $subMax, int $pearlSeq, bool $directOnly, array $help = []): bool
    {
        if ($rank->requires('clients') && $rank->minClients > 0 && ($clients[$id] ?? 0) < $rank->minClients) {
            return false;
        }
        if ($rank->requires('pearl') && $rank->minPearlFirstLevel > 0) {
            $n = 0;
            foreach ($tree->childrenOf($id) as $c) {
                if ($tree->isMember($c) && ($eff[(string) $c] ?? 0) >= $pearlSeq) {
                    $n++;
                }
            }
            foreach ($help as $helpSeq => $helpCount) {
                if ($helpSeq >= $pearlSeq) {
                    $n += $helpCount;
                }
            }
            if ($n < $rank->minPearlFirstLevel) {
                return false;
            }
        }
        if ($rank->requires('columns') && $rank->minColumns > 0 && $rank->minColumnClients > 0) {
            $n = 0;
            foreach ($tree->childrenOf($id) as $c) {
                if (($subClients[(string) $c] ?? 0) >= $rank->minColumnClients) {
                    $n++;
                }
            }
            if ($n < $rank->minColumns) {
                return false;
            }
        }
        if ($rank->requires('structure')) {
            $slots = [];
            foreach ($rank->structure as $req) {
                for ($i = 0; $i < $req->count; $i++) {
                    $slots[] = $plan->rank($req->rankCode)->sequence;
                }
            }
            rsort($slots);
            $levels = [];
            foreach ($tree->lines($id) as $l) {
                $levels[] = $directOnly ? ($tree->isMember($l) ? ($eff[(string) $l] ?? 0) : -1) : ($subMax[(string) $l] ?? -1);
            }
            foreach ($help as $helpSeq => $helpCount) {
                for ($i = 0; $i < $helpCount; $i++) {
                    $levels[] = $helpSeq;
                }
            }
            rsort($levels);
            if (count($levels) < count($slots)) {
                return false;
            }
            foreach ($slots as $i => $s) {
                if ($levels[$i] < $s) {
                    return false;
                }
            }
        }

        return true;
    }
}
