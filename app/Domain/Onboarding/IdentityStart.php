<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

final class IdentityStart
{
    public function __construct(public readonly string $ref, public readonly string $url) {}
}
