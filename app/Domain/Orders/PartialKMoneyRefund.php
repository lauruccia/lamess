<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;

/** Provider KMoney che sa riaccreditare un importo parziale (per i resi di una parte dell'ordine). */
interface PartialKMoneyRefund extends KMoneyProvider
{
    public function refundAmount(Order $order, User $buyer, string $amount, string $idempotencyKey): PaymentResult;
}
