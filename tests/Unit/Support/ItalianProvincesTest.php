<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ItalianProvinces;
use PHPUnit\Framework\TestCase;

final class ItalianProvincesTest extends TestCase
{
    public function test_names_and_abbreviations_become_the_two_letter_code(): void
    {
        $this->assertSame('MC', ItalianProvinces::normalize('Macerata'));
        $this->assertSame('MC', ItalianProvinces::normalize('  macerata '));
        $this->assertSame('MC', ItalianProvinces::normalize('Macerata (MC)'));
        $this->assertSame('MC', ItalianProvinces::normalize('mc'));
        $this->assertSame('RM', ItalianProvinces::normalize('Città Metropolitana di Roma'));
        $this->assertSame('RE', ItalianProvinces::normalize("Reggio nell'Emilia"));
        $this->assertSame('FC', ItalianProvinces::normalize('Forlì-Cesena'));
        $this->assertSame('AO', ItalianProvinces::normalize("Valle d'Aosta"));
    }

    public function test_unknown_italian_values_are_not_guessed(): void
    {
        $this->assertNull(ItalianProvinces::normalize('Atlantide'));
        $this->assertNull(ItalianProvinces::normalize(''));
        $this->assertNull(ItalianProvinces::normalize(null));
    }

    public function test_abroad_a_short_value_is_kept_and_a_long_one_is_dropped(): void
    {
        $this->assertSame('NY', ItalianProvinces::normalize('ny', 'US'));
        $this->assertNull(ItalianProvinces::normalize('Île-de-France', 'FR'));
    }
}
