<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use DateTimeImmutable;

final readonly class RankAchievementResult
{
    /** @param array<string, mixed> $detail */
    public function __construct(
        public string $partnerId,
        public string $rankCode,
        public DateTimeImmutable $achievedAt,
        public string $effectiveFrom,
        public array $detail = [],
    ) {}
}
