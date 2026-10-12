<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Engine\Model\PeriodResult;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;

abstract class EngineTestCase extends TestCase
{
    protected static function assertDec(BigDecimal|string|int $expected, BigDecimal|string|int $actual, string $msg = ''): void
    {
        $e = BigDecimal::of($expected);
        $a = BigDecimal::of($actual);
        self::assertTrue($e->isEqualTo($a), ($msg !== '' ? $msg.': ' : '')."atteso {$e}, ottenuto {$a}");
    }

    /**
     * Importi per beneficiario di un tipo di bonus.
     *
     * @return array<string, string>
     */
    protected static function amounts(PeriodResult $r, string $bonus, array $statuses = ['accrued', 'held']): array
    {
        $out = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === $bonus && in_array($l->status, $statuses, true)) {
                $out[$l->beneficiaryId] = (string) $l->amount;
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }
}
