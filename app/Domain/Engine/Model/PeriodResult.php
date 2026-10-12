<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;

final class PeriodResult
{
    /**
     * @param  array<string, PartnerStat>  $stats
     * @param  list<Line>  $lines
     * @param  list<RankAchievementResult>  $achievements
     * @param  list<RankAwardResult>  $rankAwards
     * @param  list<string>  $warnings
     * @param  list<string>  $decisionsUsed
     */
    public function __construct(
        public string $period,
        public string $planLabel,
        public string $inputHash,
        public array $stats,
        public array $lines,
        public array $achievements,
        public array $rankAwards,
        public array $warnings,
        public array $decisionsUsed,
    ) {}

    public function total(?string $bonus = null, array $statuses = ['accrued', 'held']): BigDecimal
    {
        $t = Num::zero();
        foreach ($this->lines as $l) {
            if (in_array($l->status, $statuses, true) && ($bonus === null || $l->bonus === $bonus)) {
                $t = $t->plus($l->amount);
            }
        }

        return $t;
    }

    /** @return list<Line> */
    public function linesFor(string|int $partnerId, ?string $bonus = null): array
    {
        $partnerId = (string) $partnerId;

        return array_values(array_filter(
            $this->lines,
            static fn (Line $l) => $l->beneficiaryId === $partnerId && ($bonus === null || $l->bonus === $bonus),
        ));
    }

    public function earned(string|int $partnerId, ?string $bonus = null): BigDecimal
    {
        $t = Num::zero();
        foreach ($this->linesFor($partnerId, $bonus) as $l) {
            if (in_array($l->status, ['accrued', 'held'], true)) {
                $t = $t->plus($l->amount);
            }
        }

        return $t;
    }
}
