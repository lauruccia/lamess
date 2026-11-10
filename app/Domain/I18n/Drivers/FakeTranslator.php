<?php

declare(strict_types=1);

namespace App\Domain\I18n\Drivers;

use App\Domain\I18n\MachineTranslator;

/** Solo per prove in locale e nei test: antepone il codice lingua al testo. Non si usa in produzione. */
final class FakeTranslator implements MachineTranslator
{
    public function translate(array $texts, string $from, string $to): array
    {
        return array_map(fn (string $t) => '['.$to.'] '.$t, $texts);
    }
}
