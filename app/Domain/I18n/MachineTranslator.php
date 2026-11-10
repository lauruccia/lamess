<?php

declare(strict_types=1);

namespace App\Domain\I18n;

/** Servizio di traduzione automatica (DeepL, Google, Claude…): riceve testi già protetti e restituisce le traduzioni nello stesso ordine. */
interface MachineTranslator
{
    /**
     * @param  list<string>  $texts  testi in cui segnaposto, tag e termini del brand sono già sostituiti da `<x id="N"/>`
     * @return list<string>
     *
     * @throws TranslationException
     */
    public function translate(array $texts, string $from, string $to): array;
}
