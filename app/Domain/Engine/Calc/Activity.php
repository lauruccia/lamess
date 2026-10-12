<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\Node;
use App\Domain\Engine\Model\PriorState;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;

/** Stato ATTIVO mensile (PMK p.14, p.24; decisioni del 15/09/2026). */
final class Activity
{
    /**
     * Punti validi per l'attività nel periodo, per incaricato (solo vecchio piano a PR).
     *
     * @param  list<Movement>  $movements
     * @param  array<string, true>  $closedReversals  id degli storni di periodi chiusi (non toccano il mese corrente)
     * @return array<string, BigDecimal>
     */
    public static function points(PlanConfig $plan, array $movements, array $closedReversals): array
    {
        $out = [];
        $onlyPvf = $plan->policies->is('activity_points_source', 'own_customers_pvf');
        foreach ($movements as $m) {
            if (isset($closedReversals[$m->id]) || $m->excluded) {
                continue;
            }
            if ($onlyPvf && $m->kind !== 'pvf' && ! ($plan->kosmos !== null && KosmosCalculator::isCommissionable($plan, $m))) {
                continue;
            }
            $out[$m->ownerId] = ($out[$m->ownerId] ?? Num::zero())->plus($m->pv);
        }

        return $out;
    }

    /**
     * @return array{0:bool,1:string,2:?string} [attivo, motivazione, periodo della prima soglia]
     */
    public static function evaluate(PlanConfig $plan, PeriodKey $period, Node $node, BigDecimal $pts, PriorState $prior): array
    {
        $thr = $plan->activityThreshold;
        $pol = $plan->policies;
        $prevPts = $prior->activityPointsPrev[$node->id] ?? Num::zero();
        $first = $prior->firstActivityPeriod[$node->id] ?? null;
        if ($first === null && $pts->isGreaterThanOrEqualTo($thr)) {
            $first = $period->code();
        }
        if ($node->status === 'terminated') {
            return [false, 'Contratto cessato', $first];
        }
        if ($node->status === 'suspended') {
            return [false, 'Posizione sospesa', $first];
        }
        if ($pol->flag('activity_first_month_free') && PeriodKey::of($node->since)->equals($period)) {
            return [true, "Primo mese da incaricato: attivo d'ufficio (PMK p.14)", $first];
        }
        if ($first === $period->code()) {
            return [true, "Prima soglia di {$thr} punti raggiunta in questo mese ({$pts})", $first];
        }
        if ($pol->is('activity_first_time', 'current_and_next') && $first === $period->prev()->code()) {
            return [true, 'Prima soglia raggiunta nel mese precedente: vale anche per questo mese (PMK p.24)', $first];
        }
        $rule = (string) $pol->get('activity_month_rule');
        $curOk = $pts->isGreaterThanOrEqualTo($thr);
        $prevOk = $prevPts->isGreaterThanOrEqualTo($thr);
        if ($rule === 'current' && $curOk) {
            return [true, "{$pts} punti nel mese (soglia {$thr})", $first];
        }
        if ($rule === 'previous' && $prevOk) {
            return [true, "{$prevPts} punti nel mese precedente (soglia {$thr})", $first];
        }
        if ($rule === 'current_or_previous' && ($curOk || $prevOk)) {
            return [true, "Soglia {$thr} raggiunta nel mese corrente o precedente", $first];
        }
        $missing = $thr->minus($rule === 'previous' ? $prevPts : $pts);
        $missing = $missing->isNegative() ? Num::zero() : $missing;

        return [false, 'Non attivo: mancano '.Num::points($missing)." punti (regola: {$rule})", $first];
    }
}
