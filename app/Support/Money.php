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

    /** Importo in KY (1 KY = 1 €): senza decimali se è un numero intero (1.500), altrimenti con la virgola (1.500,50). */
    public static function ky(mixed $value): string
    {
        $v = round((float) (string) $value, 2);

        return number_format($v, floor($v) === $v ? 0 : 2, ',', '.');
    }
}
