<?php

declare(strict_types=1);

namespace App\Domain\I18n\Drivers;

use App\Domain\I18n\MachineTranslator;
use App\Domain\I18n\TranslationException;
use Illuminate\Support\Facades\Http;

/** Google Cloud Translation (v2, formato html: i tag restano al loro posto). */
final class GoogleTranslator implements MachineTranslator
{
    public function __construct(private string $key) {}

    public function translate(array $texts, string $from, string $to): array
    {
        if ($this->key === '') {
            throw new TranslationException('Manca la chiave Google (GOOGLE_TRANSLATE_API_KEY nel .env).');
        }
        $response = Http::timeout(60)->retry(2, 500, throw: false)->post('https://translation.googleapis.com/language/translate/v2?key='.urlencode($this->key), [
            'q' => array_values($texts), 'source' => $from, 'target' => $to, 'format' => 'html',
        ]);
        if (! $response->successful()) {
            throw new TranslationException('Google Translate ha risposto con errore '.$response->status().'.');
        }
        $out = array_column((array) $response->json('data.translations'), 'translatedText');
        if (count($out) !== count($texts)) {
            throw new TranslationException('Google Translate ha restituito un numero di testi diverso da quello inviato.');
        }

        return array_values(array_map(fn ($t) => html_entity_decode((string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $out));
    }
}
