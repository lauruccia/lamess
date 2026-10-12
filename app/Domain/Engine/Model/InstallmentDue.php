<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use Brick\Math\BigDecimal;

final readonly class InstallmentDue
{
    public string $partnerId;

    public function __construct(public string $awardKey, int|string $partnerId, public string $rankCode, public int $number, public int $of, public BigDecimal $amount)
    {
        $this->partnerId = (string) $partnerId;
    }
}
