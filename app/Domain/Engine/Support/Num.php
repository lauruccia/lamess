<?php

declare(strict_types=1);

namespace App\Domain\Engine\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Aritmetica decimale per importi, punti e percentuali. Mai float.
 *
 * - importi in euro: 2 decimali, half-up, arrotondati per riga di compenso;
 * - punti (PV/PR/CV): 2 decimali, half-up;
 * - percentuali: frazione con 4 decimali (0.1800 = 18%).
 */
final class Num
{
    public const MONEY_SCALE = 2;

    public const RATE_SCALE = 4;

    public static function of(BigDecimal|int|string $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }

        return BigDecimal::of($value);
    }

    public static function zero(): BigDecimal
    {
        return BigDecimal::zero();
    }

    public static function money(BigDecimal|int|string $value): BigDecimal
    {
        return self::of($value)->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    public static function points(BigDecimal|int|string $value): BigDecimal
    {
        return self::of($value)->toScale(self::MONEY_SCALE, RoundingMode::HalfUp);
    }

    public static function rate(BigDecimal|int|string $value): BigDecimal
    {
        return self::of($value)->toScale(self::RATE_SCALE, RoundingMode::HalfUp);
    }

    /** Converte una percentuale ("18" o 18) in frazione: 0.1800. */
    public static function pct(BigDecimal|int|string $value): BigDecimal
    {
        return self::rate(self::of($value)->dividedBy(100, 10, RoundingMode::HalfUp));
    }

    /** Frazione → stringa percentuale a 2 decimali ("18.00"). */
    public static function pctLabel(BigDecimal $rate): string
    {
        return (string) $rate->multipliedBy(100)->toScale(2, RoundingMode::HalfUp);
    }

    /** PV suggerito per un nuovo prodotto: imponibile / euro-per-PV. */
    public static function suggestedPv(BigDecimal|int|string $netPrice, BigDecimal|int|string $eurPerPv): BigDecimal
    {
        return self::points(self::of($netPrice)->dividedBy(self::of($eurPerPv), 10, RoundingMode::HalfUp));
    }

    /**
     * Rate di pari importo al centesimo; l'ultima assorbe la differenza (somma esatta).
     *
     * @return list<BigDecimal>
     */
    public static function splitInstallments(BigDecimal|int|string $total, int $count): array
    {
        if ($count <= 0) {
            throw new InvalidArgumentException('Numero rate non valido');
        }
        $total = self::money($total);
        $base = $total->dividedBy($count, self::MONEY_SCALE, RoundingMode::HalfUp);
        $parts = array_fill(0, $count - 1, $base);
        $parts[] = $total->minus($base->multipliedBy($count - 1));

        return $parts;
    }

    /** @param iterable<BigDecimal> $values */
    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus($v);
        }

        return $total;
    }

    public static function min(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isLessThanOrEqualTo($b) ? $a : $b;
    }

    public static function max(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isGreaterThanOrEqualTo($b) ? $a : $b;
    }

    /** Formato italiano: 1.234,56 € */
    public static function eur(BigDecimal|int|string $value): string
    {
        $q = self::money($value);
        $sign = $q->isNegative() ? '-' : '';

        return $sign.number_format((float) (string) $q->abs(), 2, ',', '.').' €';
    }
}
