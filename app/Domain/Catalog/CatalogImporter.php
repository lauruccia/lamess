<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Engine\Support\Num;
use App\Models\Product;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Importa il listino da CSV (separatore «;», UTF-8): sku;nome;prezzo_ivato;pv;attivo;note.
 * Idempotente: l'SKU identifica il prodotto (si aggiorna, non si duplica). I PV sono quelli indicati nel file
 * (colonna «PR numerico» del listino Odoo); il CV resta uguale al PV.
 */
final class CatalogImporter
{
    /**
     * @param  bool  $grossPrices  true: il prezzo del file è IVA inclusa; false: è già netto
     * @return array{created: int, updated: int, rows: int}
     */
    public function import(string $path, bool $grossPrices = true, ?string $vatRate = null, bool $dryRun = false): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("File non leggibile: {$path}");
        }
        $vat = Num::of($vatRate ?? (string) config('lamess.default_vat_rate'));
        $h = fopen($path, 'r');
        $head = fgetcsv($h, 0, ';');
        $head = array_map(fn ($c) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), $head ?: []);
        foreach (['sku', 'nome', 'prezzo_ivato', 'pv', 'attivo'] as $col) {
            if (! in_array($col, $head, true)) {
                throw new InvalidArgumentException("Colonna mancante nel file: {$col}");
            }
        }
        $created = $updated = $n = 0;
        $seen = [];
        while (($raw = fgetcsv($h, 0, ';')) !== false) {
            if ($raw === [null] || count(array_filter($raw, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }
            $n++;
            $r = array_combine($head, array_pad($raw, count($head), ''));
            $sku = trim($r['sku']);
            $price = str_replace(',', '.', trim($r['prezzo_ivato']));
            $pv = str_replace(',', '.', trim($r['pv']));
            if ($sku === '' || trim($r['nome']) === '') {
                throw new InvalidArgumentException("Riga {$n}: SKU e nome sono obbligatori.");
            }
            if (isset($seen[$sku])) {
                throw new InvalidArgumentException("Riga {$n}: SKU duplicato nel file ({$sku}).");
            }
            $seen[$sku] = true;
            if (! is_numeric($price) || (float) $price < 0 || ! is_numeric($pv) || (float) $pv < 0) {
                throw new InvalidArgumentException("Riga {$n} ({$sku}): prezzo o PV non validi.");
            }
            $net = $grossPrices
                ? Num::of($price)->dividedBy(Num::of(1)->plus($vat->dividedBy(100, 10)), 8, RoundingMode::HalfUp)
                : Num::of($price)->toScale(8, RoundingMode::HalfUp);
            $data = [
                'name' => trim($r['nome']), 'net_price' => (string) $net, 'vat_rate' => (string) $vat, 'pv' => (string) Num::points($pv),
                'active' => (int) $r['attivo'] === 1, 'description' => ($r['note'] ?? '') !== '' ? trim($r['note']) : null,
            ];
            $existing = Product::where('sku', $sku)->first();
            if (! $dryRun) {
                $existing ? $existing->update($data) : Product::create($data + ['sku' => $sku]);
            }
            $existing ? $updated++ : $created++;
        }
        fclose($h);

        return ['created' => $created, 'updated' => $updated, 'rows' => $n];
    }
}
