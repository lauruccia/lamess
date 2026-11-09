<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Chi può vedere i prezzi e comprare. Con «Negozio visibile con prezzi solo dopo l'accesso» acceso (Impostazioni di sistema → Sito)
 * i visitatori non collegati vedono le pagine dei prodotti, ma senza prezzi, offerte e carrello.
 */
final class ShopAccess
{
    public static function loginRequired(): bool
    {
        return (bool) config('lamess.shop_login_required');
    }

    /** True se chi guarda può vedere prezzi e carrello: collegato, oppure negozio aperto a tutti. */
    public static function canBuy(): bool
    {
        return ! self::loginRequired() || auth()->check();
    }
}
