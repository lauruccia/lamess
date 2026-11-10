<?php

declare(strict_types=1);

namespace App\Domain\I18n;

use App\Models\TranslationString;

/**
 * Scambio con il traduttore: un file CSV per lingua (separatore «;», codifica UTF-8 con BOM, si apre in Excel) con
 * testo italiano, traduzione e stato. Il file rientra con «Importa»: le righe con una traduzione diventano «approvate».
 */
final class TranslationExchange
{
    public const HEADER = ['Testo italiano', 'Traduzione', 'Stato'];

    public function export(string $locale): string
    {
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, self::HEADER, ';');
        TranslationString::query()->where('locale', $locale)->where('orphan', false)->orderBy('id')->chunk(500, function ($rows) use ($fh): void {
            foreach ($rows as $r) {
                fputcsv($fh, [$r->source, (string) $r->text, $r->status], ';');
            }
        });
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /** @return array{updated: int, unknown: int, empty: int} */
    public function import(string $locale, string $path, bool $approve = true): array
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new TranslationException('File non leggibile.');
        }
        $first = fgets($fh);
        if ($first === false) {
            fclose($fh);
            throw new TranslationException('Il file è vuoto.');
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($fh);
        fgets($fh); // intestazione

        $updated = $unknown = $empty = 0;
        while (($cols = fgetcsv($fh, 0, $delimiter)) !== false) {
            $source = (string) ($cols[0] ?? '');
            $text = trim((string) ($cols[1] ?? ''));
            if ($source === '') {
                continue;
            }
            if ($text === '') {
                $empty++;

                continue;
            }
            $row = TranslationString::query()->where('locale', $locale)->where('key_hash', TranslationString::hashOf($source))->first();
            if ($row === null) {
                $unknown++;

                continue;
            }
            if ($row->text !== $text || ($approve && $row->status !== TranslationString::APPROVED)) {
                $row->forceFill(['text' => $text, 'status' => $approve ? TranslationString::APPROVED : TranslationString::AUTO])->save();
                $updated++;
            }
        }
        fclose($fh);

        return ['updated' => $updated, 'unknown' => $unknown, 'empty' => $empty];
    }
}
