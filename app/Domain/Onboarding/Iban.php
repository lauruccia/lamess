<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

/** Controllo formale IBAN (lunghezza per paese + modulo 97). */
final class Iban
{
    private const LENGTHS = ['IT' => 27, 'SM' => 27, 'SK' => 24, 'DE' => 22, 'FR' => 27, 'ES' => 24, 'CH' => 21, 'GB' => 22, 'NL' => 18, 'BE' => 16, 'AT' => 20, 'PT' => 25, 'LU' => 20, 'IE' => 22];

    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    public static function isValid(string $iban): bool
    {
        $i = self::normalize($iban);
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $i)) {
            return false;
        }
        $len = self::LENGTHS[substr($i, 0, 2)] ?? null;
        if ($len !== null && strlen($i) !== $len) {
            return false;
        }
        $rearranged = substr($i, 4).substr($i, 0, 4);
        $num = '';
        foreach (str_split($rearranged) as $ch) {
            $num .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $rem = 0;
        foreach (str_split($num, 7) as $chunk) {
            $rem = (int) ($rem.$chunk) % 97;
        }

        return $rem === 1;
    }
}
