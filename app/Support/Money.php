<?php

declare(strict_types=1);

namespace App\Support;

/** Formattazione degli importi in euro alla maniera italiana (1.234,50). */
final class Money
{
    public static function eur(mixed $value): string
    {
        return number_format((float) (string) $value, 2, ',', '.');
    }
}
