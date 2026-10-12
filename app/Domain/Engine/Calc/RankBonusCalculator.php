<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\RankAwardResult;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;

/** Rank Bonus una tantum, rateizzato oltre soglia (PMK p.32), e rate in scadenza. */
final class RankBonusCalculator
{
    /** @return array{0:list<Line>,1:list<RankAwardResult>} */
    public static function calculate(BonusContext $ctx): array
    {
        $plan = $ctx->plan;
        $period = $ctx->period;
        $prior = $ctx->prior;
        $pol = $plan->policies;
        /** @var list<Line> $lines */
        $lines = [];
        /** @var list<RankAwardResult> $awards */
        $awards = [];

        $grant = function (string $nid, string $code, BigDecimal $amount, string $key, string $originPeriod) use ($plan, $period, $pol, &$lines, &$awards): void {
            if ($amount->isGreaterThan($plan->rankBonusInstallmentThreshold)) {
                $parts = Num::splitInstallments($amount, $plan->rankBonusInstallments);
                $start = $pol->is('rank_bonus_installment_start', 'achievement_period') ? $period : $period->next();
                $awards[] = new RankAwardResult($key, $nid, $code, $amount, 'awarded', $parts, $start->code());
                if ($start->equals($period)) {
                    $lines[] = new Line(
                        key: "rank_installment:{$key}:1", bonus: 'rank_installment', beneficiaryId: $nid,
                        amount: $parts[0], base: $amount, sourcePartnerId: $nid,
                        explanation: ['rank' => $code, 'installment' => '1/'.count($parts), 'achieved_period' => $originPeriod],
                        decisions: ['D07'],
                    );
                }
            } else {
                $awards[] = new RankAwardResult($key, $nid, $code, $amount, 'awarded');
                $lines[] = new Line(
                    key: "rank:{$key}", bonus: 'rank', beneficiaryId: $nid, amount: $amount, base: $amount,
                    sourcePartnerId: $nid, explanation: ['rank' => $code, 'achieved_period' => $originPeriod],
                    decisions: ['D07'],
                );
            }
        };

        $ids = array_map('strval', array_keys($ctx->career->new));
        sort($ids, SORT_STRING);
        foreach ($ids as $nid) {
            if (! $ctx->tree->isMember($nid)) {
                continue;
            }
            $cands = [];
            foreach ($ctx->career->new[$nid] as [$code, $at]) {
                if ($plan->rank($code)->rankBonus->isPositive() && ! in_array("{$nid}:{$code}", $prior->rankAwards, true)) {
                    $cands[] = [$code, $at];
                }
            }
            if ($pol->is('rank_bonus_skipped_ranks', 'highest_only') && count($cands) > 1) {
                foreach (array_slice($cands, 0, -1) as [$code]) {
                    $awards[] = new RankAwardResult("{$nid}:{$code}", $nid, $code, Num::zero(), 'not_awarded', reason: 'qualifica_saltata');
                }
                $cands = array_slice($cands, -1);
            }
            foreach ($cands as [$code]) {
                $key = "{$nid}:{$code}";
                $amount = $plan->rank($code)->rankBonus;
                if ($ctx->active[$nid]) {
                    $grant($nid, $code, $amount, $key, $period->code());
                } elseif ($pol->is('rank_bonus_inactive', 'forfeit')) {
                    $awards[] = new RankAwardResult($key, $nid, $code, $amount, 'forfeited', reason: 'inattivo');
                } else {
                    $awards[] = new RankAwardResult($key, $nid, $code, $amount, 'held', reason: 'inattivo');
                }
            }
        }

        foreach ($prior->heldRankAwards as $h) {
            if (! isset($ctx->tree->nodes[$h->partnerId])) {
                continue;
            }
            if ($ctx->active[$h->partnerId] ?? false) {
                $grant($h->partnerId, $h->rankCode, $h->amount, $h->awardKey, $h->achievedPeriod);
            } elseif (
                $pol->is('rank_bonus_inactive', 'forfeit')
                || ($pol->is('rank_bonus_inactive', 'hold_n_months')
                    && PeriodKey::parse($h->achievedPeriod)->monthsUntil($period) >= $plan->rankBonusHoldMonths)
            ) {
                $awards[] = new RankAwardResult($h->awardKey, $h->partnerId, $h->rankCode, $h->amount, 'forfeited', reason: 'inattivo_oltre_termine');
            }
        }

        foreach ($prior->installmentsDue as $inst) {
            if (! isset($ctx->tree->nodes[$inst->partnerId])) {
                continue;
            }
            $node = $ctx->tree->nodes[$inst->partnerId];
            if ($node->status === 'terminated') {
                [$st, $why] = ['held', 'cessato'];
            } elseif (($ctx->active[$inst->partnerId] ?? false) || $pol->is('rank_bonus_installment_inactive', 'pay_anyway')) {
                [$st, $why] = ['accrued', ''];
            } else {
                [$st, $why] = ['held', 'inattivo'];
            }
            $lines[] = new Line(
                key: "rank_installment:{$inst->awardKey}:{$inst->number}", bonus: 'rank_installment',
                beneficiaryId: $inst->partnerId, amount: $inst->amount, base: $inst->amount, sourcePartnerId: $inst->partnerId,
                status: $st, reason: $why,
                explanation: ['rank' => $inst->rankCode, 'installment' => "{$inst->number}/{$inst->of}"],
                decisions: ['D07'],
            );
        }

        return [$lines, $awards];
    }
}
