<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;

/** Driver di sviluppo: addebito KMoney sempre riuscito. NON usare in produzione. */
final class FakeKMoneyProvider implements PartialKMoneyRefund
{
    public function name(): string
    {
        return 'kmoney-fake';
    }

    public function enabled(): bool
    {
        return true;
    }

    public function charge(Order $order, User $buyer): PaymentResult
    {
        return new PaymentResult(true, 'FAKE-KM-'.Str::upper(Str::random(10)));
    }

    public function refundAmount(Order $order, User $buyer, string $amount, string $idempotencyKey): PaymentResult
    {
        return new PaymentResult(true, 'FAKE-KM-REFUND-'.Str::upper(Str::random(8)));
    }

    public function refund(Order $order, User $buyer): PaymentResult
    {
        return new PaymentResult(true, 'FAKE-KM-REFUND-'.Str::upper(Str::random(8)));
    }
}
