<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;

/** Matching Bonus: % sui guadagni (Direct + Team) della downline fino a 6 livelli (PMK p.29–31). */
final class MatchingCalculator
{
    /**
     * @param  list<Line>  $lines  righe già calcolate (direct, team, clawback)
     * @return list<Line>
     */
    public static function calculate(BonusContext $ctx, array $lines): array
    {
        $plan = $ctx->plan;
        $tree = $ctx->tree;
        /** @var array<string, BigDecimal> $earnings */
        $earnings = [];
        foreach ($lines as $l) {
            if (in_array($l->bonus, ['direct', 'team', 'clawback'], true) && in_array($l->status, ['accrued', 'held'], true)) {
                $earnings[$l->beneficiaryId] = ($earnings[$l->beneficiaryId] ?? Num::zero())->plus($l->amount);
            }
        }
        ksort($earnings, SORT_STRING);
        $out = [];
        $depth = $plan->matchingDepth();
        $genealogical = $plan->policies->is('matching_level_counting', 'genealogical');
        foreach ($earnings as $c => $base) {
            $c = (string) $c;
            if ($base->isZero()) {
                continue;
            }
            $level = 0;
            foreach ($tree->ancestors($c) as $u) {
                $counts = $genealogical ? true : $ctx->active[$u];
                if (! $counts) {
                    $potential = $plan->matchingRate($ctx->rankCode($u), $level + 1);
                    if ($potential->isPositive() && $level + 1 <= $depth) {
                        $out[] = new Line(
                            key: "matching:{$c}:{$u}", bonus: 'matching', beneficiaryId: $u,
                            amount: Num::money($base->multipliedBy($potential)), base: $base, rate: $potential,
                            level: $level + 1, sourcePartnerId: $c, status: 'excluded', reason: 'inattivo_compresso',
                            decisions: ['D05', 'D01'],
                        );
                    }

                    continue;
                }
                $level++;
                if ($level > $depth) {
                    break;
                }
                $r = $plan->matchingRate($ctx->rankCode($u), $level);
                if ($r->isZero()) {
                    continue;
                }
                [$st, $why] = $ctx->matchingStatus($u);
                $out[] = new Line(
                    key: "matching:{$c}:{$u}", bonus: 'matching', beneficiaryId: $u,
                    amount: Num::money($base->multipliedBy($r)), base: $base, rate: $r, level: $level,
                    sourcePartnerId: $c, status: $st, reason: $why,
                    explanation: [
                        'formula' => "{$base} × ".Num::pctLabel($r)."% (livello {$level})",
                        'base_note' => 'Direct + Team dell\'incaricato in downline, esclusi Matching, Rank e FSB',
                        'rank' => $ctx->rankCode($u),
                    ],
                    decisions: ['D05', 'D01'],
                );
            }
        }

        return $out;
    }
}
