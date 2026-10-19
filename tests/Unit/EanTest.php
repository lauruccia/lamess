<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Ean;
use PHPUnit\Framework\TestCase;

final class EanTest extends TestCase
{
    public function test_valid_codes(): void
    {
        $this->assertTrue(Ean::isValid('4006381333931'));   // EAN-13 di riferimento
        $this->assertTrue(Ean::isValid('96385074'));        // EAN-8 di riferimento
        $this->assertTrue(Ean::isValid('4006 3813 33931'), 'spazi ignorati');
    }

    public function test_invalid_codes(): void
    {
        $this->assertFalse(Ean::isValid('4006381333932'), 'cifra di controllo errata');
        $this->assertFalse(Ean::isValid('12345'));
        $this->assertFalse(Ean::isValid(''));
        $this->assertFalse(Ean::isValid(null));
    }

    public function test_check_digit(): void
    {
        $this->assertSame(1, Ean::checkDigit('400638133393'));
        $this->assertSame(4, Ean::checkDigit('9638507'));
    }

    public function test_svg_is_drawn_only_for_valid_codes_and_has_95_modules(): void
    {
        $svg = Ean::svg('4006381333931', 1);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('4006381333931', $svg);
        $this->assertStringContainsString('viewBox="0 0 115 88"', $svg, '95 moduli + 2 zone di rispetto da 10');
        $this->assertSame('', Ean::svg('4006381333932'));
    }
}
