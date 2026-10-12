<?php

declare(strict_types=1);

namespace App\Domain\Engine\Plan;

use App\Domain\Engine\Calc\FsbCalculator;
use Brick\Math\BigDecimal;

/** Step del Fast Start Bonus (PMK 070726 p.33–35), calcolato da {@see FsbCalculator}. */
final readonly class FsbStepDef
{
    public function __construct(
        public int $number,
        public ?int $days,
        public int $directsRequired,
        public BigDecimal $directMinPv,
        public BigDecimal $pvTarget,
        public ?string $requiredRankCode,
        public bool $fiftyPercentRule,
        public BigDecimal $amount,
        public string $kind = 'standard', // standard | legs (step 10)
        public int $legsRequired = 0,
        public int $legsStepNumber = 0,
        public string $source = '',
    ) {}
}
