<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use Brick\Math\BigDecimal;

final class RankAwardResult
{
    /** @param list<BigDecimal> $installments */
    public function __construct(
        public string $awardKey,
        public string $partnerId,
        public string $rankCode,
        public BigDecimal $amount,
        public string $status, // awarded | held | forfeited | not_awarded
        public array $installments = [],
        public string $firstInstallmentPeriod = '',
        public string $reason = '',
    ) {}
}
