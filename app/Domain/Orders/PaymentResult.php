<?php

declare(strict_types=1);

namespace App\Domain\Orders;

final class PaymentResult
{
    public function __construct(public readonly bool $paid, public readonly ?string $reference = null, public readonly ?string $error = null, public readonly ?string $redirectUrl = null) {}
}
