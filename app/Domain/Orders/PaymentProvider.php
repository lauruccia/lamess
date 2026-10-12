<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;

/** Pagamento online con carta. Driver reali (Stripe, Nexi…) implementano questa interfaccia. */
interface PaymentProvider
{
    public function name(): string;

    /** @param array<string,mixed> $details dati specifici del provider (token carta, ecc.) */
    public function charge(Order $order, array $details = []): PaymentResult;
}
