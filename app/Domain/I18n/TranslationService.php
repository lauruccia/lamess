<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use App\Domain\I18n\Drivers\AnthropicTranslator;
use App\Domain\I18n\Drivers\DeepLTranslator;
use App\Domain\I18n\Drivers\FakeTranslator;
use App\Domain\I18n\Drivers\GoogleTranslator;
use App\Models\TranslationString;

/**
 * Traduzione automatica delle frasi mancanti. Prima di inviare un testo al servizio si proteggono segnaposto (:nome, %s),
 * tag HTML, entità e termini del brand sostituendoli con `<x id="N"/>`; dopo la risposta si rimettono al loro posto e,
 * se non tornano tutti, la riga resta vuota e segnalata. Ogni riga tradotta resta «automatica»: la approva il traduttore.
 */
final class TranslationService
{
    private const BATCH = 30;

    public function __construct(private ?MachineTranslator $driver = null) {}

    public static function makeDriver(): MachineTranslator
    {
        return match ((string) config('i18n.driver')) {
            'deepl' => new DeepLTranslator((string) config('i18n.deepl.key'), (string) config('i18n.deepl.url')),
            'google' => new GoogleTranslator((string) config('i18n.google.key')),
            'anthropic' => new AnthropicTranslator((string) config('i18n.anthropic.key'), (string) config('i18n.anthropic.model')),
            'fake' => new FakeTranslator,
            default => throw new TranslationException('Servizio di traduzione automatica non valido: '.config('i18n.driver')),
        };
    }

    public function isReal(): bool
    {
        return (string) config('i18n.driver') !== 'fake' || app()->environment('local', 'testing');
    }

    /**
     * Traduce le righe date (o tutte le mancanti di una lingua).
     *
     * @param  list<int>|null  $ids
     * @return array{translated: int, failed: int, error: ?string}
     */
    public function translate(string $locale, ?array $ids = null, int $limit = 200): array
    {
        if ($locale === Locales::default()) {
            throw new TranslationException('L\'italiano è la lingua sorgente: non si traduce.');
        }
        if (! $this->isReal()) {
            throw new TranslationException('Servizio di traduzione automatica non configurato: imposta I18N_DRIVER (deepl, google o anthropic) e la chiave nel .env.');
        }
        $driver = $this->driver ?? self::makeDriver();

        $rows = TranslationString::query()->where('locale', $locale)->where('orphan', false)
            ->when($ids !== null, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->where(fn ($w) => $w->whereNull('text')->orWhere('text', '')))
            ->orderBy('id')->limit($limit)->get();

        $translated = $failed = 0;
        $error = null;
        foreach ($rows->chunk(self::BATCH) as $chunk) {
            $masked = [];
            $maps = [];
            foreach ($chunk->values() as $i => $row) {
                [$masked[$i], $maps[$i]] = $this->mask($row->source);
            }
            try {
                $out = $driver->translate($masked, Locales::default(), $locale);
            } catch (TranslationException $e) {
                $error = $e->getMessage();
                $failed += $chunk->count();

                break;
            }
            foreach ($chunk->values() as $i => $row) {
                $text = $this->unmask((string) ($out[$i] ?? ''), $maps[$i]);
                if ($text === null || trim($text) === '') {
                    $failed++;

                    continue;
                }
                $row->forceFill(['text' => $text, 'status' => TranslationString::AUTO])->save();
                $translated++;
            }
        }

        return ['translated' => $translated, 'failed' => $failed, 'error' => $error];
    }

    /**
     * @return array{0: string, 1: list<string>} testo protetto, elementi originali nell'ordine delle etichette
     */
    public function mask(string $text): array
    {
        $terms = array_map(fn (string $t) => preg_quote($t, '/'), (array) config('i18n.glossary', []));
        usort($terms, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        $parts = ['<[^>]+>', '&[a-zA-Z0-9#]+;', ':[a-z_][a-z0-9_]*', '%(?:\d+\$)?[sd]'];
        if ($terms !== []) {
            $parts[] = '(?<![\p{L}])(?:'.implode('|', $terms).')(?![\p{L}])';
        }
        $items = [];
        $masked = preg_replace_callback('/'.implode('|', $parts).'/u', function (array $m) use (&$items): string {
            $items[] = $m[0];

            return '<x id="'.count($items).'"/>';
        }, $text);

        return [(string) $masked, $items];
    }

    /** @param list<string> $items */
    public function unmask(string $translated, array $items): ?string
    {
        $used = 0;
        $out = preg_replace_callback('/<x\s+id="(\d+)"\s*\/?>(?:\s*<\/x>)?/i', function (array $m) use ($items, &$used): string {
            $used++;

            return $items[(int) $m[1] - 1] ?? "\u{0}";
        }, $translated);

        if ($out === null || $used !== count($items) || str_contains($out, "\u{0}")) {
            return null;
        }

        return $out;
    }
}
