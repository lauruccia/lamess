<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use DateTimeImmutable;

final readonly class AchievedRank
{
    public string $partnerId;

    public function __construct(int|string $partnerId, public string $rankCode, public DateTimeImmutable $achievedAt, public string $source = 'calculated')
    {
        $this->partnerId = (string) $partnerId;
    }
}
