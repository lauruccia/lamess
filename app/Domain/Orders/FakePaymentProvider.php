<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Str;

/** Driver di sviluppo: addebito sempre riuscito, salvo `simulate => decline`. NON usare in produzione. */
final class FakePaymentProvider implements PaymentProvider, RefundsCardPayments
{
    public function name(): string
    {
        return 'fake';
    }

    public function refundPayment(Payment $payment, string $amount, string $idempotencyKey): PaymentResult
    {
        return new PaymentResult(true, 'FAKE-RF-'.Str::upper(Str::random(10)));
    }

    public function charge(Order $order, array $details = []): PaymentResult
    {
        if (($details['simulate'] ?? null) === 'decline') {
            return new PaymentResult(false, null, 'Carta rifiutata (simulazione)');
        }

        return new PaymentResult(true, 'FAKE-CH-'.Str::upper(Str::random(10)));
    }
}
