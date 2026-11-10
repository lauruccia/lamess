<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use App\Models\TranslationString;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Carica le frasi dai file di lingua (lang/xx.json, consegnati con il codice) e poi ci sovrappone quelle del database,
 * che il super_admin modifica da pannello: la cache per lingua si svuota a ogni salvataggio.
 * Con «mostra anche le traduzioni non approvate» spento, valgono solo le righe approvate del database.
 */
final class DatabaseTranslationLoader implements Loader
{
    public function __construct(private Loader $files) {}

    /** @return array<string, mixed> */
    public function load($locale, $group, $namespace = null): array
    {
        $lines = [];
        $isJson = $group === '*' && $namespace === '*';
        if (! $isJson || config('i18n.show_unreviewed', true)) {
            $lines = $this->files->load($locale, $group, $namespace);
        }
        if ($isJson) {
            // Laravel non consulta il file JSON della lingua di riserva: una frase senza traduzione ricade sul testo italiano
            // (anche su quello che il super_admin ha corretto da pannello) già qui, come base della lingua.
            $base = $locale === config('i18n.default', 'it') ? [] : $this->stored((string) config('i18n.default', 'it'));
            $lines = array_replace($base, $lines, $this->stored((string) $locale));
        }

        return $lines;
    }

    /** @return array<string, string> testo italiano → testo nella lingua */
    private function stored(string $locale): array
    {
        try {
            return Cache::rememberForever('i18n.strings.'.$locale, function () use ($locale): array {
                if (! Schema::hasTable('translation_strings')) {
                    return [];
                }
                $q = TranslationString::query()->where('locale', $locale)->where('orphan', false)->whereNotNull('text')->where('text', '!=', '');
                if (! config('i18n.show_unreviewed', true)) {
                    $q->where('status', TranslationString::APPROVED);
                }

                return $q->pluck('text', 'source')->all();
            });
        } catch (\Throwable) {
            return [];
        }
    }

    public function addNamespace($namespace, $hint): void
    {
        $this->files->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path): void
    {
        $this->files->addJsonPath($path);
    }

    /** @return array<string, string> */
    public function namespaces(): array
    {
        return $this->files->namespaces();
    }
}
