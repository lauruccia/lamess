<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Support\Num;

/** Direct Bonus e Team Bonus differenziale con compressione (PMK p.25–28). */
final class DirectTeamCalculator
{
    /** @return list<Line> */
    public static function calculate(BonusContext $ctx, Movement $m): array
    {
        $plan = $ctx->plan;
        $tree = $ctx->tree;
        $lines = [];
        $base = $ctx->baseAmount($m);
        $decisions = $m->kind === 'pvc' ? ['D02', 'D03', 'D04'] : ['D02', 'D03'];
        $differential = $m->kind === 'pvc'
            && $plan->policies->is('personal_purchase_direct_beneficiary', 'upline_differential');
        $b = $differential ? $m->ownerId : self::directBeneficiary($ctx, $m);
        if ($b === null) {
            $ctx->warnings[] = "Movimento {$m->id}: nessun beneficiario del Direct Bonus";

            return $lines;
        }
        $rateB = $ctx->rate($b);
        [$status, $reason] = $differential ? ['accrued', ''] : $ctx->directStatus($b);
        if (! $differential) {
            $lines[] = new Line(
                key: "direct:{$m->id}", bonus: 'direct', beneficiaryId: $b,
                amount: Num::money($base->multipliedBy($rateB)), base: $base, rate: $rateB,
                sourceMovementId: $m->id, sourcePartnerId: $m->ownerId, status: $status, reason: $reason,
                explanation: [
                    'formula' => "{$base} × ".Num::pctLabel($rateB).'%',
                    'rank' => $ctx->rankCode($b),
                    'movement_kind' => $m->kind,
                    'note' => ($m->kind === 'pvc' && $b !== $m->ownerId)
                        ? 'Acquisto personale dell\'incaricato: Direct allo sponsor (decisione K2)' : '',
                ],
                decisions: $decisions,
            );
        }

        $paid = $status !== 'excluded' ? $rateB : Num::zero();
        $maxRate = $plan->maxDirectRate();
        $path = [['partner' => $b, 'rank' => $ctx->rankCode($b), 'rate' => (string) $rateB, 'role' => $differential ? 'acquisto personale: nessun Direct a chi compra' : 'vendita diretta']];
        $u = $tree->parentOf($b);
        while ($u !== null && $paid->isLessThan($maxRate)) {
            $uRate = $ctx->rate($u);
            [$st, $why] = $ctx->indirectStatus($u);
            $diff = $uRate->minus($paid);
            if ($st === 'excluded') {
                if ($diff->isPositive()) {
                    $lines[] = new Line(
                        key: "team:{$m->id}:{$u}", bonus: 'team', beneficiaryId: $u,
                        amount: Num::money($base->multipliedBy($diff)), base: $base, rate: $diff,
                        sourceMovementId: $m->id, sourcePartnerId: $m->ownerId, status: 'excluded', reason: $why,
                        explanation: ['path' => $path, 'absorbed_before' => (string) $paid, 'own_rate' => (string) $uRate],
                        decisions: ['D04', 'D05', 'D01', 'D06'],
                    );
                }
                $action = 'saltato (compressione)';
                if ($plan->policies->is('team_ineligible_upline', 'absorb')) {
                    $paid = Num::max($paid, $uRate);
                    $action = 'quota persa';
                }
                $path[] = ['partner' => $u, 'rank' => $ctx->rankCode($u), 'rate' => (string) $uRate, 'role' => "non idoneo: {$why}; {$action}"];
            } else {
                if ($diff->isPositive()) {
                    $lines[] = new Line(
                        key: "team:{$m->id}:{$u}", bonus: 'team', beneficiaryId: $u,
                        amount: Num::money($base->multipliedBy($diff)), base: $base, rate: $diff,
                        sourceMovementId: $m->id, sourcePartnerId: $m->ownerId, status: $st, reason: $why,
                        explanation: [
                            'formula' => "{$base} × (".Num::pctLabel($uRate).'% − '.Num::pctLabel($paid).'%)',
                            'path' => $path, 'absorbed_before' => (string) $paid, 'own_rate' => (string) $uRate,
                        ],
                        decisions: ['D04', 'D05', 'D01'],
                    );
                    $paid = $uRate;
                    $role = 'differenziale';
                } else {
                    $role = 'pari grado o inferiore: nessun differenziale';
                }
                $path[] = ['partner' => $u, 'rank' => $ctx->rankCode($u), 'rate' => (string) $uRate, 'role' => $role];
            }
            $u = $tree->parentOf($u);
        }

        return $lines;
    }

    private static function directBeneficiary(BonusContext $ctx, Movement $m): ?string
    {
        if ($m->kind === 'pvf') {
            return $m->ownerId;
        }

        return match ((string) $ctx->plan->policies->get('personal_purchase_direct_beneficiary')) {
            'self' => $m->ownerId,
            'sponsor' => $ctx->tree->parentOf($m->ownerId),
            default => null,
        };
    }
}
