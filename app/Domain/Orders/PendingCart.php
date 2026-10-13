<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Models\Order;
use Illuminate\Http\Request;

/**
 * Il carrello resta in sessione finché l'ordine non è pagato.
 *
 * Con carta o KMoney il cliente passa da una pagina esterna: se torna indietro (freccia, «annulla», ritorno al sito) il carrello
 * deve essere ancora lì. Per questo, dopo aver registrato l'ordine, ci si ricorda solo quale ordine è in attesa; il carrello si
 * svuota quando quell'ordine risulta pagato (o quando il cliente torna dalla pagina di pagamento a pagamento riuscito).
 */
final class PendingCart
{
    public const KEY = 'cart_pending_order';

    private const CART_KEYS = ['cart', 'cart_others', 'cart_codes'];

    /** Ordine registrato ma non ancora pagato: il carrello resta, finché non risulta pagato. */
    public static function hold(Request $r, Order $order): void
    {
        $r->session()->put(self::KEY, $order->id);
    }

    /** Acquisto concluso: svuota carrello e promemoria. */
    public static function clear(Request $r): void
    {
        $r->session()->forget(array_merge([self::KEY], self::CART_KEYS));
    }

    /**
     * Allinea la sessione con lo stato dell'ordine in attesa e lo restituisce se è ancora da pagare.
     * Pagato (o già avanzato): il carrello si svuota. Annullato o sparito: il carrello resta, il promemoria cade.
     */
    public static function pending(Request $r): ?Order
    {
        if (! $r->hasSession() || ! $r->session()->has(self::KEY)) {
            return null;
        }
        $order = Order::find((int) $r->session()->get(self::KEY));
        if ($order === null || $order->buyer_user_id !== $r->user()?->id || $order->status === 'cancelled') {
            $r->session()->forget(self::KEY);

            return null;
        }
        if ($order->status === 'pending_payment') {
            return $order;
        }
        self::clear($r);

        return null;
    }
}
