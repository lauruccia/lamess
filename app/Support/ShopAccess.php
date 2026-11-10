<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Features\Feature;

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

    /** True se chi guarda può vedere prezzi e carrello: negozio acceso da «Funzionalità e viste» e chi guarda è collegato, oppure il negozio è aperto a tutti. */
    public static function canBuy(): bool
    {
        return Feature::enabled('site.shop') && (! self::loginRequired() || auth()->check());
    }
}
