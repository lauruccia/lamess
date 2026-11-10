<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use App\Models\Language;
use App\Models\TranslationString;
use Illuminate\Support\Facades\DB;

/**
 * «Cerca nuove stringhe»: allinea la tabella dei testi con il codice. Aggiunge le frasi nuove a ogni lingua,
 * porta nel database le traduzioni dei file `lang/xx.json` consegnati con il codice (stato «automatica», da rivedere)
 * e segna come «orfane» le righe il cui testo italiano non esiste più. Non tocca mai le righe già modificate a mano.
 */
final class TranslationSync
{
    public function __construct(private StringScanner $scanner) {}

    /** @return array{found: int, added: int, imported: int, orphaned: int, revived: int} */
    public function run(): array
    {
        @set_time_limit(0);
        $found = $this->scanner->scan();
        $sources = array_keys($found);
        $hashes = [];
        foreach ($sources as $s) {
            $hashes[TranslationString::hashOf($s)] = $s;
        }

        $added = $imported = $orphaned = $revived = 0;
        $default = Locales::default();
        foreach (Language::query()->orderBy('sort')->pluck('code') as $code) {
            $fileLines = $this->fileLines($code);
            $existing = TranslationString::query()->where('locale', $code)->get(['id', 'key_hash', 'text', 'status', 'orphan'])->keyBy('key_hash');

            $new = [];
            $now = now()->toDateTimeString();
            foreach ($hashes as $hash => $source) {
                $row = $existing->get($hash);
                $fileText = $code === $default ? null : ($fileLines[$source] ?? null);
                if ($row === null) {
                    $has = is_string($fileText) && $fileText !== '';
                    $new[] = ['locale' => $code, 'key_hash' => $hash, 'source' => $source, 'text' => $has ? $fileText : null,
                        'status' => $has ? TranslationString::AUTO : TranslationString::MISSING, 'orphan' => false, 'created_at' => $now, 'updated_at' => $now];
                    $has ? $imported++ : null;

                    continue;
                }
                if ($row->orphan) {
                    TranslationString::whereKey($row->id)->update(['orphan' => false]);
                    $revived++;
                }
                if (($row->text === null || $row->text === '') && is_string($fileText) && $fileText !== '') {
                    TranslationString::whereKey($row->id)->update(['text' => $fileText, 'status' => TranslationString::AUTO]);
                    $imported++;
                }
            }
            foreach (array_chunk($new, 400) as $chunk) {
                DB::table('translation_strings')->insert($chunk);
            }
            $added += count($new);

            $gone = $existing->filter(fn ($r) => ! isset($hashes[$r->key_hash]) && ! $r->orphan)->pluck('id')->all();
            foreach (array_chunk($gone, 400) as $ids) {
                TranslationString::whereIn('id', $ids)->update(['orphan' => true]);
            }
            $orphaned += count($gone);
            Locales::forgetStrings($code);
        }

        return ['found' => count($sources), 'added' => $added, 'imported' => $imported, 'orphaned' => $orphaned, 'revived' => $revived];
    }

    /** Prepara una lingua nuova: una riga vuota per ogni testo italiano, più le frasi già tradotte nel suo file lang/xx.json. @return int testi creati */
    public function seedLanguage(string $code): int
    {
        $fileLines = $this->fileLines($code);
        $now = now()->toDateTimeString();
        $count = 0;
        TranslationString::query()->where('locale', Locales::default())->where('orphan', false)->orderBy('id')->chunk(400, function ($rows) use ($code, $fileLines, $now, &$count): void {
            $batch = [];
            foreach ($rows as $r) {
                $text = $fileLines[$r->source] ?? null;
                $has = is_string($text) && $text !== '';
                $batch[] = ['locale' => $code, 'key_hash' => $r->key_hash, 'source' => $r->source, 'text' => $has ? $text : null,
                    'status' => $has ? TranslationString::AUTO : TranslationString::MISSING, 'orphan' => false, 'created_at' => $now, 'updated_at' => $now];
            }
            DB::table('translation_strings')->insertOrIgnore($batch);
            $count += count($batch);
        });
        Locales::forgetStrings($code);

        return $count;
    }

    /** @return array<string, string> */
    private function fileLines(string $code): array
    {
        $path = lang_path($code.'.json');
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? array_filter($data, 'is_string') : [];
    }
}
