<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\Node;
use App\Domain\Engine\Model\PriorState;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\RankDef;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** Punti carriera (PR), qualifiche, regola del 50% e requisiti di struttura. */
final class CareerState
{
    /** @var array<string, list<array{0:int,1:DateTimeImmutable,2:string}>> [seq, achievedAt, code] */
    public array $achieved = [];

    /** @var array<string, int> */
    public array $highest = [];

    /** @var array<string, BigDecimal> */
    public array $personal = [];

    /** @var array<string, BigDecimal> */
    public array $openingPersonal = [];

    /** @var array<string, BigDecimal> */
    public array $group = [];

    /** @var array<string, int> */
    public array $submax = [];

    /** @var array<string, list<array{0:string,1:DateTimeImmutable,2:array<string,mixed>}>> */
    public array $new = [];

    /** @var array<string, int> clienti personali per incaricato (solo piano Incaricati) */
    public array $clientCounts = [];

    /** @var array<string, array<int, int>> agenti virtuali regalati: incaricato => sequenza qualifica => quanti (solo requisiti di qualifica) */
    public array $rankHelps = [];

    public function __construct(public readonly PlanConfig $plan, public readonly Tree $tree, PriorState $prior)
    {
        foreach ($prior->achievedRanks as $a) {
            if (isset($tree->nodes[$a->partnerId]) && $plan->hasCode($a->rankCode)) {
                $this->achieved[$a->partnerId][] = [$plan->rank($a->rankCode)->sequence, $a->achievedAt, $a->rankCode];
            }
        }
        $entry = $plan->entryRank();
        foreach ($tree->nodes as $id => $node) {
            $id = (string) $id;
            $list = $this->achieved[$id] ?? [];
            if (! array_filter($list, static fn (array $x) => $x[0] === 0)) {
                $list[] = [0, $node->since, $entry->code];
            }
            usort($list, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
            $this->achieved[$id] = $list;
            $this->highest[$id] = max(array_map(static fn (array $x) => $x[0], $list));
            $this->personal[$id] = Num::points($prior->personalPrOpening[$id] ?? 0);
        }
        $this->openingPersonal = $this->personal;
    }

    /**
     * PMK p.19 (piano precedente): «Incaricato» subito; le qualifiche successive dal 1° giorno del mese successivo.
     * Piano Incaricati ($immediate): ogni qualifica vale dal giorno in cui è raggiunta, quindi si paga già nel mese stesso.
     */
    public static function effectiveFrom(RankDef $rank, DateTimeImmutable $achievedAt, bool $immediate = false): DateTimeImmutable
    {
        if ($rank->sequence === 0 || $immediate) {
            return PeriodKey::toLocal($achievedAt)->setTime(0, 0);
        }

        return PeriodKey::of($achievedAt)->next()->firstDay();
    }

    public function prPoints(Movement $m): BigDecimal
    {
        return $this->plan->policies->is('pr_source', 'cv') ? $m->cv : $m->pv;
    }

    /** @var array<string, int> */
    private array $subtreeClientsMemo = [];

    /** Clienti dell'intera colonna: i propri più quelli di tutta la discendenza. */
    private function subtreeClients(string $id): int
    {
        if (isset($this->subtreeClientsMemo[$id])) {
            return $this->subtreeClientsMemo[$id];
        }
        $sum = $this->clientCounts[$id] ?? 0;
        foreach ($this->tree->childrenOf($id) as $c) {
            $sum += $this->subtreeClients((string) $c);
        }

        return $this->subtreeClientsMemo[$id] = $sum;
    }

    /** Agenti regalati con qualifica Pearl o superiore (contano al 1° livello per i requisiti della qualifica). */
    public function helpedPearls(string $id, int $pearlSeq): int
    {
        $n = 0;
        foreach ($this->rankHelps[$id] ?? [] as $seq => $count) {
            if ($seq >= $pearlSeq) {
                $n += $count;
            }
        }

        return $n;
    }

    /** @return array{0:bool,1:array<string,mixed>} */
    public function qualification(string $id, RankDef $rank): array
    {
        $plan = $this->plan;
        $tree = $this->tree;
        $required = $rank->prRequired;
        $lines = $tree->lines($id);
        $linePr = [];
        foreach ($lines as $l) {
            $linePr[$l] = $this->group[$l] ?? Num::zero();
        }
        $personal = $this->personal[$id];
        $cap = null;
        if ($rank->fiftyPercentRule && $required->isPositive()) {
            $cap = Num::points($required->multipliedBy($plan->fiftyRuleShare));
            $pers = $plan->policies->is('personal_pr_in_fifty_rule', 'uncapped') ? $personal : Num::min($personal, $cap);
            $countedLines = array_map(static fn (BigDecimal $v) => Num::min($v, $cap), $linePr);
            $counted = $pers->plus(Num::sum($countedLines));
        } else {
            $countedLines = $linePr;
            $counted = $this->group[$id] ?? $personal;
        }

        $slots = [];
        foreach ($rank->requires('structure') ? $rank->structure : [] as $req) {
            for ($i = 0; $i < $req->count; $i++) {
                $slots[] = $plan->rank($req->rankCode)->sequence;
            }
        }
        rsort($slots);
        $levels = [];
        foreach ($lines as $l) {
            $levels[$l] = $plan->policies->is('structure_depth', 'direct_only')
                ? ($tree->isMember($l) ? $this->highest[$l] : -1)
                : ($this->submax[$l] ?? -1);
        }
        $ordered = array_values($levels);
        foreach ($this->rankHelps[$id] ?? [] as $helpSeq => $helpCount) {
            for ($i = 0; $i < $helpCount; $i++) {
                $ordered[] = $helpSeq; // ogni agente regalato è una colonna a sé al 1° livello
            }
        }
        rsort($ordered);
        $structOk = count($ordered) >= count($slots);
        if ($structOk) {
            foreach ($slots as $i => $s) {
                if ($ordered[$i] < $s) {
                    $structOk = false;
                    break;
                }
            }
        }
        $missing = [];
        if (! $structOk) {
            foreach ($slots as $i => $s) {
                if ($i >= count($ordered) || $ordered[$i] < $s) {
                    $missing[] = $plan->bySeq($s)->code;
                }
            }
        }
        $prOk = $counted->isGreaterThanOrEqualTo($required);
        $clientsOk = ! $rank->requires('clients') || $rank->minClients <= 0 || ($this->clientCounts[$id] ?? 0) >= $rank->minClients;
        $pearlOk = true;
        $pearlCount = 0;
        if ($rank->requires('pearl') && $rank->minPearlFirstLevel > 0 && $plan->kosmos !== null) {
            $pearlSeq = $plan->rank($plan->kosmos->pearlRank)->sequence;
            foreach ($tree->childrenOf($id) as $c) {
                if ($tree->isMember($c) && ($this->highest[$c] ?? 0) >= $pearlSeq) {
                    $pearlCount++;
                }
            }
            $pearlCount += $this->helpedPearls($id, $pearlSeq);
            $pearlOk = $pearlCount >= $rank->minPearlFirstLevel;
        }
        $columnsOk = true;
        $columnsCounted = 0;
        if ($rank->requires('columns') && $rank->minColumns > 0 && $rank->minColumnClients > 0) {
            foreach ($tree->childrenOf($id) as $c) {
                if ($this->subtreeClients((string) $c) >= $rank->minColumnClients) {
                    $columnsCounted++;
                }
            }
            $columnsOk = $columnsCounted >= $rank->minColumns;
        }
        $lineDetail = [];
        foreach ($lines as $l) {
            $lineDetail[$l] = [
                'pr' => (string) $linePr[$l],
                'counted' => (string) $countedLines[$l],
                'best_rank' => $levels[$l] >= 0 ? $plan->bySeq($levels[$l])->code : null,
            ];
        }
        $detail = [
            'rank' => $rank->code,
            'pr_required' => (string) $required,
            'pr_counted' => (string) Num::points($counted),
            'pr_group' => (string) ($this->group[$id] ?? $personal),
            'personal_pr' => (string) $personal,
            'line_cap' => $cap === null ? null : (string) $cap,
            'lines' => $lineDetail,
            'structure_required' => array_map(static fn (int $s) => $plan->bySeq($s)->code, $slots),
            'structure_missing' => $missing,
            'pr_ok' => $prOk,
            'structure_ok' => $structOk,
        ];
        if ($rank->minClients > 0) {
            $detail['clients_required'] = $rank->minClients;
            $detail['clients_counted'] = $this->clientCounts[$id] ?? 0;
            $detail['clients_ok'] = $clientsOk;
        }
        if ($rank->minPearlFirstLevel > 0) {
            $detail['pearl_first_level_required'] = $rank->minPearlFirstLevel;
            $detail['pearl_first_level_counted'] = $pearlCount;
            $detail['pearl_first_level_ok'] = $pearlOk;
        }

        if ($rank->requires('columns') && $rank->minColumns > 0 && $rank->minColumnClients > 0) {
            $detail['columns_required'] = $rank->minColumns;
            $detail['column_clients_required'] = $rank->minColumnClients;
            $detail['columns_counted'] = $columnsCounted;
            $detail['columns_ok'] = $columnsOk;
        }

        return [$prOk && $structOk && $clientsOk && $pearlOk && $columnsOk, $detail];
    }

    /** Ricalcola PR di gruppo e promuove (anche di più livelli) chi soddisfa i requisiti. */
    public function checkpoint(DateTimeImmutable $at): void
    {
        $tree = $this->tree;
        foreach ($tree->bottomUp as $id) {
            $sum = $this->personal[$id];
            foreach ($tree->childrenOf($id) as $c) {
                $sum = $sum->plus($this->group[$c]);
            }
            $this->group[$id] = $sum;
            if ($tree->isMember($id)) {
                while ($this->plan->hasSeq($this->highest[$id] + 1)) {
                    $rank = $this->plan->bySeq($this->highest[$id] + 1);
                    [$ok, $detail] = $this->qualification($id, $rank);
                    if (! $ok) {
                        break;
                    }
                    $this->highest[$id] = $rank->sequence;
                    $this->achieved[$id][] = [$rank->sequence, $at, $rank->code];
                    $this->new[$id][] = [$rank->code, $at, $detail];
                }
            }
            $own = $tree->isMember($id) ? $this->highest[$id] : -1;
            $best = $own;
            foreach ($tree->childrenOf($id) as $c) {
                $best = max($best, $this->submax[$c]);
            }
            $this->submax[$id] = $best;
        }
    }

    /** @param list<Movement> $movements ordinati per istante */
    public function run(PeriodKey $period, array $movements): void
    {
        $this->checkpoint($period->start());
        $byDay = [];
        foreach ($movements as $m) {
            $byDay[PeriodKey::toLocal($m->at)->format('Y-m-d')][] = $m;
        }
        ksort($byDay);
        foreach ($byDay as $dayMoves) {
            $last = $dayMoves[0]->at;
            foreach ($dayMoves as $m) {
                $this->personal[$m->ownerId] = $this->personal[$m->ownerId]->plus($this->prPoints($m));
                if ($m->at > $last) {
                    $last = $m->at;
                }
            }
            $this->checkpoint($last);
        }
    }

    public function effectiveSeq(Node $node, PeriodKey $period): int
    {
        $best = 0;
        foreach ($this->achieved[$node->id] as [$seq, $at]) {
            $immediate = $this->plan->kosmos !== null;
            $effective = self::effectiveFrom($this->plan->bySeq($seq), $at, $immediate);
            // piano Incaricati: vale nel mese se raggiunta entro la fine del mese; piano precedente: dal 1° giorno del mese
            if ($seq === 0 || ($immediate ? $effective < $period->end() : $effective <= $period->firstDay())) {
                $best = max($best, $seq);
            }
        }
        if ($node->rankCapCode !== null) {
            $best = min($best, $this->plan->rank($node->rankCapCode)->sequence);
        }

        return $best;
    }
}
