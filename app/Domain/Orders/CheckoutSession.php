<?php

declare(strict_types=1);

namespace App\Domain\Orders;

final class CheckoutSession
{
    public function __construct(public readonly string $id, public readonly string $url) {}
}
