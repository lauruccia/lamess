<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;

/** Pagamento con KMoney: il saldo KMoney del cliente viene addebitato in automatico. Il driver reale implementa questa interfaccia. */
interface KMoneyProvider
{
    public function name(): string;

    /** Se false il metodo non compare nel checkout. */
    public function enabled(): bool;

    /**
     * Addebita la quota KMoney dell'ordine (kmoney_amount) sul conto del cliente.
     * Esiti: pagato; da collegare (error = 'not_linked'); conferma richiesta (redirectUrl); errore.
     */
    public function charge(Order $order, User $buyer): PaymentResult;

    /** Riaccredita al cliente la quota KMoney già addebitata (ordine non completato). */
    public function refund(Order $order, User $buyer): PaymentResult;
}
