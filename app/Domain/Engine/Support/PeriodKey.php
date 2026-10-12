<?php

declare(strict_types=1);

namespace App\Domain\Engine\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Periodo provvigionale = mese solare in Europe/Rome (PMK p.14).
 * Il fuso è un parametro (config lamess.timezone) ma il default è Roma.
 */
final class PeriodKey
{
    public const DEFAULT_TZ = 'Europe/Rome';

    private static string $tz = self::DEFAULT_TZ;

    public function __construct(public readonly int $year, public readonly int $month)
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Mese non valido: {$month}");
        }
    }

    public static function useTimezone(string $tz): void
    {
        self::$tz = $tz;
    }

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::$tz);
    }

    public static function parse(string $code): self
    {
        if (! preg_match('/^(\d{4})-(\d{2})$/', $code, $m)) {
            throw new InvalidArgumentException("Codice periodo non valido: {$code}");
        }

        return new self((int) $m[1], (int) $m[2]);
    }

    public static function of(DateTimeInterface $moment): self
    {
        $local = self::toLocal($moment);

        return new self((int) $local->format('Y'), (int) $local->format('n'));
    }

    public static function toLocal(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)->setTimezone(self::timezone());
    }

    /** Parsing di test/seed: stringa in ora locale. */
    public static function at(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, self::timezone());
    }

    public function code(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    public function firstDay(): DateTimeImmutable
    {
        return new DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $this->year, $this->month), self::timezone());
    }

    public function next(): self
    {
        return $this->month === 12 ? new self($this->year + 1, 1) : new self($this->year, $this->month + 1);
    }

    public function prev(): self
    {
        return $this->month === 1 ? new self($this->year - 1, 12) : new self($this->year, $this->month - 1);
    }

    public function start(): DateTimeImmutable
    {
        return $this->firstDay();
    }

    /** Istante esclusivo di fine periodo (inizio del mese successivo). */
    public function end(): DateTimeImmutable
    {
        return $this->next()->start();
    }

    public function contains(DateTimeInterface $moment): bool
    {
        return $moment >= $this->start() && $moment < $this->end();
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }

    public function monthsUntil(self $other): int
    {
        return ($other->year * 12 + $other->month) - ($this->year * 12 + $this->month);
    }

    public function __toString(): string
    {
        return $this->code();
    }
}
