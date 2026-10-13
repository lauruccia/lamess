<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\Node;
use App\Domain\Engine\Model\PartnerStat;
use App\Domain\Engine\Model\PeriodInput;
use App\Domain\Engine\Model\PeriodResult;
use App\Domain\Engine\Model\RankAchievementResult;
use App\Domain\Engine\Support\Canonical;
use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use RuntimeException;

/**
 * Calcolo di un periodo provvigionale: unico punto d'ingresso per calcolo ufficiale,
 * stima live e simulazione. Codice puro: nessun accesso al database né all'orologio.
 *
 * Pipeline: punti e carriera → attività e rank efficace → Direct/Team (o storni) →
 * Matching → Rank Bonus e rate → Fast Start Bonus.
 */
final class PeriodCalculator
{
    public static function inputHash(PeriodInput $input): string
    {
        return Canonical::hash($input);
    }

    /**
     * Piano Incaricati: nessuno stato ATTIVO. Restano esclusi dalle commissioni indirette solo i contratti cessati o sospesi.
     *
     * @return array{0:bool,1:string,2:?string}
     */
    private static function withoutActiveStatus(Node $node): array
    {
        return match ($node->status) {
            'terminated' => [false, 'Contratto cessato', null],
            'suspended' => [false, 'Posizione sospesa', null],
            default => [true, 'Il piano Incaricati non prevede lo stato attivo', null],
        };
    }

    public static function calculate(PeriodInput $input): PeriodResult
    {
        $plan = $input->plan;
        $period = $input->period;
        $prior = $input->prior;
        $tree = new Tree($input->nodes);
        $warnings = [];

        $movements = $input->movements;
        usort($movements, static fn (Movement $a, Movement $b) => [$a->at, $a->id] <=> [$b->at, $b->id]);
        $seen = [];
        foreach ($movements as $m) {
            if (isset($seen[$m->id])) {
                throw new InvalidArgumentException("Movimento duplicato {$m->id}");
            }
            $seen[$m->id] = true;
            if (! $period->contains($m->at)) {
                throw new InvalidArgumentException("Movimento {$m->id} fuori dal periodo {$period}");
            }
            if (! isset($tree->nodes[$m->ownerId])) {
                throw new InvalidArgumentException("Movimento {$m->id}: incaricato {$m->ownerId} assente");
            }
        }

        /** @var array<string, true> $closedReversals */
        $closedReversals = [];
        foreach ($movements as $m) {
            if ($m->reversesId !== null && isset($prior->originalMovements[$m->reversesId])) {
                $closedReversals[$m->id] = true;
            }
        }

        // 1. punti e carriera
        $career = new CareerState($plan, $tree, $prior);
        $clients = [];
        if ($plan->kosmos !== null) {
            $clients = KosmosCalculator::clientCounts($plan, $period, $movements, $closedReversals, $input->customerFirstPurchase, $input->customerCoverage);
            $career->clientCounts = $clients;
        }
        $career->run($period, $movements);

        $stats = [];
        foreach ($tree->nodes as $id => $_) {
            $id = (string) $id;
            $stats[$id] = new PartnerStat($id, $career->openingPersonal[$id]);
        }
        /** @var array<string, BigDecimal> $periodPv */
        $periodPv = [];
        foreach ($movements as $m) {
            $st = $stats[$m->ownerId];
            if ($m->kind === 'pvf') {
                $st->personalPvf = $st->personalPvf->plus($m->pv);
            } else {
                $st->personalPvc = $st->personalPvc->plus($m->pv);
            }
            $st->personalCv = $st->personalCv->plus($m->cv);
            $periodPv[$m->ownerId] = ($periodPv[$m->ownerId] ?? Num::zero())->plus($m->pv);
        }
        /** @var array<string, BigDecimal> $groupPv */
        $groupPv = [];
        foreach ($tree->bottomUp as $id) {
            $sum = $periodPv[$id] ?? Num::zero();
            foreach ($tree->childrenOf($id) as $c) {
                $sum = $sum->plus($groupPv[$c]);
            }
            $groupPv[$id] = $sum;
        }

        // 2. attività e qualifica efficace
        $hasActiveStatus = $plan->kosmos === null; // il piano Incaricati non ha lo stato ATTIVO: nessuna soglia di spesa o di punti
        $actPts = $hasActiveStatus ? Activity::points($plan, $movements, $closedReversals) : [];
        $active = [];
        $eff = [];
        foreach ($tree->nodes as $id => $node) {
            $id = (string) $id;
            [$ok, $reason, $first] = $hasActiveStatus
                ? Activity::evaluate($plan, $period, $node, $actPts[$id] ?? Num::zero(), $prior)
                : self::withoutActiveStatus($node);
            $active[$id] = $ok;
            $eff[$id] = $career->effectiveSeq($node, $period);
            if ($plan->kosmos !== null && $plan->policies->is('kosmos_rank_maintenance', 'clients') && ! $plan->kosmos->ranksProtectedIn($period->end()) && ! $node->rankHeldIn($period->end())) {
                while ($eff[$id] > 0 && $plan->bySeq($eff[$id])->requires('clients') && ($clients[$id] ?? 0) < $plan->bySeq($eff[$id])->minClients) {
                    $eff[$id]--;
                }
            }
            $st = $stats[$id];
            $st->activityPoints = $actPts[$id] ?? Num::zero();
            $st->active = $ok;
            $st->activeReason = $reason;
            $st->firstActivityPeriod = $first;
            $st->effectiveRank = $plan->bySeq($eff[$id])->code;
            $st->achievedRank = $plan->bySeq($career->highest[$id])->code;
            $st->personalPrClosing = $career->personal[$id];
            $st->groupPrClosing = $career->group[$id];
            $st->groupPv = $groupPv[$id];
            $lp = [];
            foreach ($tree->lines($id) as $l) {
                $lp[$l] = (string) $career->group[$l];
            }
            $st->linesPr = $lp;
        }

        $ctx = new BonusContext($plan, $period, $tree, $career, $prior, $active, $eff);
        foreach ($tree->nodes as $id => $_) {
            $id = (string) $id;
            $stats[$id]->indirectStatus = implode(':', array_filter($ctx->indirectStatus($id)));
            $stats[$id]->matchingStatus = implode(':', array_filter($ctx->matchingStatus($id)));
        }

        // 3. Direct e Team (o storni di periodi chiusi)
        /** @var list<Line> $lines */
        $lines = [];
        $awards = [];
        if ($plan->kosmos !== null) {
            // Piano Incaricati: diretti, indiretti, indiretti estesi e Bonus di Struttura (nessun Team/Matching/Rank Bonus/FSB)
            array_push($lines, ...KosmosCalculator::calculate($ctx, $movements, $clients, $closedReversals, $input->customerFirstPurchase));
            $movements = [];
        }
        foreach ($movements as $m) {
            if ($m->excluded) {
                $ctx->warnings[] = "Movimento {$m->id} escluso dai bonus";

                continue;
            }
            $claw = $m->reversesId !== null ? ClawbackCalculator::calculate($ctx, $m) : null;
            array_push($lines, ...($claw ?? DirectTeamCalculator::calculate($ctx, $m)));
        }

        if ($plan->kosmos === null) {
            // 4. Matching su Direct + Team
            array_push($lines, ...MatchingCalculator::calculate($ctx, $lines));

            // 5. Rank Bonus e rate
            [$rbLines, $awards] = RankBonusCalculator::calculate($ctx);
            array_push($lines, ...$rbLines);

            // 6. Fast Start Bonus (PMK 070726 p.33–35)
            array_push($lines, ...FsbCalculator::calculate($ctx, $movements));
        }

        $achievements = [];
        $newIds = array_map('strval', array_keys($career->new));
        sort($newIds, SORT_STRING);
        foreach ($newIds as $nid) {
            foreach ($career->new[$nid] as [$code, $at, $detail]) {
                $achievements[] = new RankAchievementResult(
                    $nid, $code, $at, CareerState::effectiveFrom($plan->rank($code), $at, $plan->kosmos !== null)->format('Y-m-d'), $detail,
                );
            }
        }
        foreach ($tree->nodes as $id => $_) {
            $id = (string) $id;
            $nxt = $career->highest[$id] + 1;
            if ($plan->hasSeq($nxt)) {
                [, $detail] = $career->qualification($id, $plan->bySeq($nxt));
                $stats[$id]->nextRank = $detail;
            }
        }

        $keys = array_map(static fn (Line $l) => $l->key, $lines);
        if (count($keys) !== count(array_unique($keys))) {
            throw new RuntimeException('Chiavi di riga duplicate nel calcolo');
        }
        $decisions = ['D01'];
        foreach ($lines as $l) {
            foreach ($l->decisions as $d) {
                $decisions[] = $d;
            }
        }
        $decisions = array_values(array_unique($decisions));
        sort($decisions);

        return new PeriodResult(
            period: $period->code(), planLabel: $plan->label, inputHash: self::inputHash($input),
            stats: $stats, lines: $lines, achievements: $achievements, rankAwards: $awards,
            warnings: array_merge($warnings, $ctx->warnings), decisionsUsed: $decisions,
        );
    }
}
