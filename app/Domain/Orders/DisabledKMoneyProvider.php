<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;

/** KMoney non attivo: il metodo non compare nel checkout. */
final class DisabledKMoneyProvider implements KMoneyProvider
{
    public function name(): string
    {
        return 'kmoney-off';
    }

    public function enabled(): bool
    {
        return false;
    }

    public function charge(Order $order, User $buyer): PaymentResult
    {
        return new PaymentResult(false, null, 'Il pagamento con KMoney non è attivo.');
    }

    public function refund(Order $order, User $buyer): PaymentResult
    {
        return new PaymentResult(false, null, 'KMoney non è attivo: il rimborso va fatto a mano dal portale KMoney.');
    }
}
