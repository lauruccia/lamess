<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use Brick\Math\BigDecimal;

final readonly class HeldRankAward
{
    public string $partnerId;

    public function __construct(public string $awardKey, int|string $partnerId, public string $rankCode, public BigDecimal $amount, public string $achievedPeriod)
    {
        $this->partnerId = (string) $partnerId;
    }
}
