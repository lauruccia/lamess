<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;

/**
 * Provider che incassa su una pagina sicura esterna (Stripe Checkout, Nexi HPP…): il cliente viene
 * mandato lì e il pagamento si conferma più tardi, via webhook.
 */
interface RedirectPaymentProvider extends PaymentProvider
{
    /** @throws OrderException se il servizio non risponde */
    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): CheckoutSession;
}
