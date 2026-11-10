<?php

declare(strict_types=1);

namespace App\Domain\I18n\Drivers;

use App\Domain\I18n\MachineTranslator;
use App\Domain\I18n\TranslationException;
use Illuminate\Support\Facades\Http;

/** DeepL API: le etichette `<x id="N"/>` si lasciano intatte con tag_handling=xml e ignore_tags=x. */
final class DeepLTranslator implements MachineTranslator
{
    private const TARGETS = ['en' => 'EN-GB', 'pt' => 'PT-PT'];

    public function __construct(private string $key, private string $url) {}

    public function translate(array $texts, string $from, string $to): array
    {
        if ($this->key === '') {
            throw new TranslationException('Manca la chiave DeepL (DEEPL_API_KEY nel .env).');
        }
        $response = Http::withHeaders(['Authorization' => 'DeepL-Auth-Key '.$this->key])->timeout(60)->retry(2, 500, throw: false)
            ->post($this->url, [
                'text' => array_values($texts), 'source_lang' => strtoupper($from), 'target_lang' => self::TARGETS[$to] ?? strtoupper($to),
                'tag_handling' => 'xml', 'ignore_tags' => ['x'], 'preserve_formatting' => true,
            ]);
        if (! $response->successful()) {
            throw new TranslationException('DeepL ha risposto con errore '.$response->status().'.');
        }
        $out = array_column((array) $response->json('translations'), 'text');
        if (count($out) !== count($texts)) {
            throw new TranslationException('DeepL ha restituito un numero di testi diverso da quello inviato.');
        }

        return array_values(array_map('strval', $out));
    }
}
