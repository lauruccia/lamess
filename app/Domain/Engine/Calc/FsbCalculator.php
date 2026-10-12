<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Plan\FsbStepDef;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Fast Start Bonus (PMK 070726 p.33–35; Guida FSB rev.1.1).
 *
 * Ogni step si vince se, ENTRO i giorni dalla registrazione (firma della Lettera IVD), sono veri insieme:
 *  - almeno N incaricati diretti con almeno `direct_min_pv` PV propri (PMK 070726: «Incaricati Diretti con 100PV»);
 *  - PV cumulati dalla registrazione (personali + linee) pari al traguardo, con regola del 50% per linea dallo step 2;
 *  - qualifica raggiunta (o efficace, secondo la politica) almeno pari a quella dello step.
 * I requisiti sono cumulativi e non decrescono: basta valutarli alla fine del periodo o alla scadenza, se precedente.
 * Step 10: step 9 vinto e almeno 3 linee distinte con un incaricato che ha vinto lo step 9. Nessuna scadenza per lo sponsor.
 * Gli step già riconosciuti in periodi chiusi (`prior->fsbAwards`) non si ripagano.
 */
final class FsbCalculator
{
    /**
     * @param  list<Movement>  $movements  movimenti confermati del periodo
     * @return list<Line>
     */
    public static function calculate(BonusContext $ctx, array $movements): array
    {
        $plan = $ctx->plan;
        if (! $plan->fsbEnabled || $plan->fsbSteps === []) {
            return [];
        }
        $tree = $ctx->tree;
        $periodStart = $ctx->period->start();
        $lastInstant = $ctx->period->end()->modify('-1 second');

        /** @var array<string, list<array{0:DateTimeImmutable,1:BigDecimal}>> $byOwner */
        $byOwner = [];
        $all = $ctx->prior->fsbMovements;
        foreach ($movements as $m) {
            $all[] = $m;
        }
        foreach ($all as $m) {
            if ($m->excluded || ! isset($tree->nodes[$m->ownerId])) {
                continue;
            }
            $byOwner[$m->ownerId][] = [$m->at, $m->pv];
        }

        $standard = array_values(array_filter($plan->fsbSteps, static fn (FsbStepDef $s) => $s->kind === 'standard' && $s->days !== null));
        usort($standard, static fn (FsbStepDef $a, FsbStepDef $b) => $a->number <=> $b->number);
        $legs = array_values(array_filter($plan->fsbSteps, static fn (FsbStepDef $s) => $s->kind === 'legs'));

        // PV cumulati di un incaricato fino a un istante (da una data minima)
        $personalPv = static function (string $id, DateTimeImmutable $from, DateTimeImmutable $to) use (&$byOwner): BigDecimal {
            $sum = Num::zero();
            foreach ($byOwner[$id] ?? [] as [$at, $pv]) {
                if ($at >= $from && $at <= $to) {
                    $sum = $sum->plus($pv);
                }
            }

            return $sum;
        };
        $subtreePv = static function (string $root, DateTimeImmutable $from, DateTimeImmutable $to) use ($tree, $personalPv): BigDecimal {
            $sum = $personalPv($root, $from, $to);
            foreach ($tree->descendants($root) as $d) {
                $sum = $sum->plus($personalPv($d, $from, $to));
            }

            return $sum;
        };

        /** @var array<string, array<int, true>> $won step vinti (chiusi + di questo periodo) */
        $won = [];
        foreach ($ctx->prior->fsbAwards as $key) {
            [$pid, $n] = explode(':', $key) + [1 => null];
            if ($n !== null) {
                $won[$pid][(int) $n] = true;
            }
        }

        /** @var list<Line> $lines */
        $lines = [];
        $basis = (string) $plan->policies->get('fsb_rank_basis');
        $blocks = $plan->policies->is('fsb_missed_step', 'blocks_following');

        foreach ($tree->nodes as $id => $node) {
            $id = (string) $id;
            if (! $tree->isMember($id)) {
                continue;
            }
            $start = $node->since;
            $stopped = false;
            foreach ($standard as $s) {
                $deadline = self::deadline($start, (int) $s->days);
                $already = isset($won[$id][$s->number]);
                if ($already) {
                    continue;
                }
                if ($stopped || $deadline < $periodStart) {
                    // finestra chiusa in un periodo precedente senza premio: perso
                    if ($blocks && $deadline < $periodStart) {
                        $stopped = true;
                    }

                    continue;
                }
                $t = $deadline < $lastInstant ? $deadline : $lastInstant;
                if ($start > $t) {
                    continue;
                }
                [$ok, $detail] = self::standardStep($ctx, $s, $id, $start, $t, $basis, $personalPv, $subtreePv);
                if ($ok) {
                    $won[$id][$s->number] = true;
                    [$status, $reason] = $ctx->directStatus($id);
                    $lines[] = new Line(
                        key: "fsb:{$id}:{$s->number}", bonus: 'fsb', beneficiaryId: $id, amount: Num::money($s->amount),
                        base: Num::money($detail['pv']), sourcePartnerId: $id, status: $status, reason: $reason,
                        explanation: $detail + ['step' => $s->number, 'deadline' => $deadline->format('Y-m-d H:i'), 'valutato_al' => $t->format('Y-m-d H:i')],
                        decisions: ['D08'],
                    );
                } elseif ($blocks && $deadline < $lastInstant) {
                    $stopped = true; // scaduto in questo periodo senza premio
                }
            }
        }

        foreach ($legs as $s) {
            $stepRef = $s->legsStepNumber;
            foreach ($tree->nodes as $id => $node) {
                $id = (string) $id;
                if (! $tree->isMember($id) || isset($won[$id][$s->number]) || ! isset($won[$id][$stepRef])) {
                    continue;
                }
                $lineCount = 0;
                $viaLines = [];
                foreach ($tree->lines($id) as $root) {
                    $members = array_merge([$root], $tree->descendants($root));
                    foreach ($members as $y) {
                        if ($tree->isMember($y) && isset($won[$y][$stepRef])) {
                            $lineCount++;
                            $viaLines[] = ['linea' => $root, 'consulente' => $y];
                            break;
                        }
                    }
                }
                if ($lineCount >= $s->legsRequired) {
                    [$status, $reason] = $ctx->directStatus($id);
                    $lines[] = new Line(
                        key: "fsb:{$id}:{$s->number}", bonus: 'fsb', beneficiaryId: $id, amount: Num::money($s->amount),
                        base: Num::zero(), sourcePartnerId: $id, status: $status, reason: $reason,
                        explanation: ['step' => $s->number, 'linee' => $viaLines, 'richieste' => $s->legsRequired, 'rif_step' => $stepRef],
                        decisions: ['D09'],
                    );
                    $won[$id][$s->number] = true;
                }
            }
        }

        usort($lines, static fn (Line $a, Line $b) => strcmp($a->key, $b->key));

        return $lines;
    }

    /** Fine della giornata (ora locale) del giorno di registrazione + N giorni. */
    public static function deadline(DateTimeImmutable $start, int $days): DateTimeImmutable
    {
        $local = PeriodKey::toLocal($start);

        return $local->modify("+{$days} days")->setTime(23, 59, 59);
    }

    /**
     * @return array{0:bool,1:array<string,mixed>}
     */
    private static function standardStep(
        BonusContext $ctx, FsbStepDef $s, string $id, DateTimeImmutable $start, DateTimeImmutable $t,
        string $basis, \Closure $personalPv, \Closure $subtreePv,
    ): array {
        $tree = $ctx->tree;
        $plan = $ctx->plan;

        $directs = 0;
        foreach ($tree->childrenOf($id) as $c) {
            if (! $tree->isMember($c) || $tree->nodes[$c]->since > $t) {
                continue;
            }
            if ($personalPv($c, $tree->nodes[$c]->since, $t)->isGreaterThanOrEqualTo($s->directMinPv)) {
                $directs++;
            }
        }

        $personal = $personalPv($id, $start, $t);
        $total = $personal;
        $counted = $personal;
        $cap = $s->pvTarget->multipliedBy($plan->fiftyRuleShare);
        $linesPv = [];
        foreach ($tree->lines($id) as $root) {
            $pv = $subtreePv($root, $start, $t);
            $total = $total->plus($pv);
            $linesPv[$root] = (string) $pv;
            $counted = $counted->plus($s->fiftyPercentRule ? Num::min($pv, $cap) : $pv);
        }
        $pvOk = $counted->isGreaterThanOrEqualTo($s->pvTarget);

        $rankOk = true;
        $rankLabel = $s->requiredRankCode;
        if ($s->requiredRankCode !== null) {
            $need = $plan->rank($s->requiredRankCode)->sequence;
            if ($basis === 'effective') {
                $have = $ctx->eff[$id];
            } else {
                $have = 0;
                foreach ($ctx->career->achieved[$id] ?? [] as [$seq, $at]) {
                    if ($at <= $t && $seq > $have) {
                        $have = $seq;
                    }
                }
            }
            $rankOk = $have >= $need;
        }

        $ok = $directs >= $s->directsRequired && $pvOk && $rankOk;

        return [$ok, [
            'diretti_con_pv' => $directs, 'diretti_richiesti' => $s->directsRequired, 'pv_richiesti' => (string) $s->pvTarget,
            'pv' => (string) $total, 'pv_conteggiati' => (string) $counted, 'pv_personali' => (string) $personal,
            'pv_per_linea' => $linesPv, 'qualifica_richiesta' => $rankLabel, 'regola_50' => $s->fiftyPercentRule,
        ]];
    }
}
