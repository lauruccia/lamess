<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Payment;

/** Provider carta che sa rimborsare (anche in parte) un pagamento già incassato. */
interface RefundsCardPayments
{
    /** @param string $idempotencyKey la stessa chiave non rimborsa due volte (ripetere la chiamata è sicuro) */
    public function refundPayment(Payment $payment, string $amount, string $idempotencyKey): PaymentResult;
}
