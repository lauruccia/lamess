<?php

declare(strict_types=1);

namespace App\Domain\Plan;

use App\Models\Period;

/** Lo stato ATTIVO esiste solo nel vecchio piano a PR: il piano Incaricati non lo prevede, quindi le schermate lo nascondono. */
final class ActiveStatus
{
    private static ?bool $applies = null;

    /** Vero se il piano dell'ultimo periodo prevede lo stato ATTIVO (senza periodi: sì, come il piano ufficiale). */
    public static function applies(): bool
    {
        if (self::$applies === null) {
            $period = Period::query()->orderByDesc('code')->first();
            self::$applies = $period === null || $period->planVersion->plan()->kosmos === null;
        }

        return self::$applies;
    }

    public static function forget(): void
    {
        self::$applies = null;
    }
}
