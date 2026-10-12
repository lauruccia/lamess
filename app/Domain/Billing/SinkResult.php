<?php

declare(strict_types=1);

namespace App\Domain\Billing;

final class SinkResult
{
    public function __construct(public readonly bool $ok, public readonly ?string $reference = null, public readonly ?string $error = null) {}
}
