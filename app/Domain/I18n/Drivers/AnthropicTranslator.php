<?php

declare(strict_types=1);

namespace App\Domain\I18n\Drivers;

use App\Domain\I18n\MachineTranslator;
use App\Domain\I18n\TranslationException;
use Illuminate\Support\Facades\Http;

/** Claude (API Anthropic): rispetta tono, glossario ed etichette `<x id="N"/>`; risponde con un elenco JSON. */
final class AnthropicTranslator implements MachineTranslator
{
    private const NAMES = ['it' => 'Italian', 'en' => 'English', 'nl' => 'Dutch', 'fr' => 'French', 'de' => 'German', 'es' => 'Spanish', 'pt' => 'Portuguese'];

    public function __construct(private string $key, private string $model) {}

    public function translate(array $texts, string $from, string $to): array
    {
        if ($this->key === '') {
            throw new TranslationException('Manca la chiave Anthropic (ANTHROPIC_API_KEY nel .env).');
        }
        $source = self::NAMES[$from] ?? $from;
        $target = self::NAMES[$to] ?? $to;
        $prompt = "Translate each string of the JSON array below from {$source} to {$target} for the website of a premium cosmetic coffee brand. "
            .'Keep the same tone (warm, elegant, clear). Keep every <x id="N"/> tag exactly as it is, in a natural position. Do not translate brand names. '
            .'Reply with ONLY a JSON array of strings, same length and order, no comments.'."\n\n".json_encode(array_values($texts), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = Http::withHeaders(['x-api-key' => $this->key, 'anthropic-version' => '2023-06-01'])->timeout(120)->retry(2, 800, throw: false)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model, 'max_tokens' => 8000, 'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        if (! $response->successful()) {
            throw new TranslationException('Anthropic ha risposto con errore '.$response->status().'.');
        }
        $text = (string) $response->json('content.0.text');
        $start = strpos($text, '[');
        $end = strrpos($text, ']');
        $decoded = $start !== false && $end !== false ? json_decode(substr($text, $start, $end - $start + 1), true) : null;
        if (! is_array($decoded) || count($decoded) !== count($texts)) {
            throw new TranslationException('Claude non ha restituito un elenco valido di traduzioni.');
        }

        return array_values(array_map('strval', $decoded));
    }
}
