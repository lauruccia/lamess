<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

/** Controllo formale IBAN (lunghezza per paese + modulo 97). */
final class Iban
{
    private const LENGTHS = ['IT' => 27, 'SM' => 27, 'SK' => 24, 'DE' => 22, 'FR' => 27, 'ES' => 24, 'CH' => 21, 'GB' => 22, 'NL' => 18, 'BE' => 16, 'AT' => 20, 'PT' => 25, 'LU' => 20, 'IE' => 22, 'TN' => 24, 'MA' => 28, 'EG' => 29, 'TR' => 26, 'AE' => 23, 'SA' => 24];

    /** Paesi dell'area SEPA (UE, SEE, Regno Unito, Svizzera e piccoli Stati aderenti): qui il bonifico SEPA funziona. */
    private const SEPA = ['AD', 'AT', 'BE', 'BG', 'CH', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HR', 'HU', 'IE', 'IS', 'IT', 'LI', 'LT', 'LU', 'LV', 'MC', 'MT', 'NL', 'NO', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK', 'SM', 'VA'];

    public static function normalize(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    public static function country(string $iban): string
    {
        return substr(self::normalize($iban), 0, 2);
    }

    /** Vero se l'IBAN è di un paese dell'area SEPA. Non controlla che l'IBAN sia valido. */
    public static function isSepa(string $iban): bool
    {
        return in_array(self::country($iban), self::SEPA, true);
    }

    public static function normalizeBic(?string $bic): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', (string) $bic));
    }

    /** BIC/SWIFT: 8 o 11 caratteri (4 lettere banca, 2 paese, 2 località, 3 filiale facoltativa). */
    public static function isValidBic(?string $bic): bool
    {
        return (bool) preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', self::normalizeBic($bic));
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
