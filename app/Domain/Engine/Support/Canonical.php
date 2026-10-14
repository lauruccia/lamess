<?php

declare(strict_types=1);

namespace App\Domain\Engine\Support;

use BackedEnum;
use Brick\Math\BigDecimal;
use DateTimeInterface;
use UnitEnum;

/**
 * Serializzazione canonica per l'hash dei dati di input: stessi dati → stesso hash,
 * indipendentemente dall'ordine delle chiavi o dalla scala dei decimali.
 */
final class Canonical
{
    /** Campi aggiunti dopo il rilascio: se vuoti non entrano nell'hash, così gli hash dei calcoli esistenti non cambiano. */
    private const OPTIONAL_WHEN_EMPTY = ['kosmos', 'minClients', 'minPearlFirstLevel', 'minColumns', 'minColumnClients', 'disabled', 'customerId', 'customerFirstPurchase', 'amountEur', 'orderId', 'vapEur', 'validityMonths', 'clientCount', 'makesClient', 'customerCoverage', 'rankGraceDays', 'activationDate', 'rankHoldUntil'];

    public static function hash(mixed $value): string
    {
        return hash('sha256', (string) json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof BigDecimal) {
            $str = (string) $value;
            if (str_contains($str, '.')) {
                $str = rtrim(rtrim($str, '0'), '.');
            }

            return $str === '' || $str === '-0' ? '0' : $str;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:sP');
        }
        if ($value instanceof BackedEnum) {
            return $value->value;
        }
        if ($value instanceof UnitEnum) {
            return $value->name;
        }
        if (is_object($value)) {
            $value = array_filter(
                get_object_vars($value),
                static fn (mixed $v, string|int $k) => ! (in_array($k, self::OPTIONAL_WHEN_EMPTY, true) && ($v === null || $v === [] || $v === 0)),
                ARRAY_FILTER_USE_BOTH,
            );
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[(string) $k] = self::normalize($v);
            }
            if (! array_is_list($value)) {
                ksort($out, SORT_STRING);
            }

            return $out;
        }

        return $value;
    }
}
