<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Engine\Support\Num;
use App\Models\CreditNote;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use RuntimeException;
use ZipArchive;

/**
 * Pacchetto per il commercialista (ECONS di TeamSystem e simili): uno ZIP con gli XML FatturaPA dei documenti emessi nel periodo
 * (cartella `xml/`, gli stessi file che vanno allo SdI) e un riepilogo CSV con una riga per documento e aliquota IVA.
 * Non cambia nulla nel portale. Le ricevute dei privati non sono fatture e restano fuori. Le note di credito hanno importi negativi nel CSV.
 */
final class AccountingExport
{
    public function __construct(private readonly FatturaPaBuilder $builder) {}

    public static function available(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @return array{zip: string, invoices: int, credit_notes: int, receipts_skipped: int, errors: array<int, string>}
     */
    public function forPeriod(string $from, string $until): array
    {
        if (! self::available()) {
            throw new RuntimeException('Sul server manca l\'estensione PHP «zip»: chiedi all\'hosting di attivarla.');
        }
        $start = CarbonImmutable::parse($from, 'Europe/Rome')->startOfDay()->setTimezone((string) config('app.timezone'));
        $end = CarbonImmutable::parse($until, 'Europe/Rome')->endOfDay()->setTimezone((string) config('app.timezone'));

        $all = Invoice::with('order')->whereBetween('issued_at', [$start, $end])->orderBy('issued_at')->orderBy('id')->get();
        $skipped = $all->where('type', '!=', 'invoice')->count();
        $invoices = $all->where('type', 'invoice')->values();
        $notes = CreditNote::with(['invoice', 'order'])->whereBetween('issued_at', [$start, $end])->orderBy('issued_at')->orderBy('id')->get()
            ->filter(fn (CreditNote $n) => $n->invoice?->type === 'invoice')->values();

        $rows = [self::HEADER];
        $errors = [];
        $files = [];
        foreach ($invoices->concat($notes) as $doc) {
            $filename = '';
            $status = 'OK';
            try {
                $built = $this->builder->build($doc);
                $filename = $built['filename'];
                $files[$filename] = $built['xml'];
            } catch (BillingException $e) {
                $status = 'ERRORE: '.$e->getMessage();
                $errors[] = $doc->number.': '.$e->getMessage();
            }
            foreach ($this->rowsFor($doc, $filename, $status) as $r) {
                $rows[] = $r;
            }
        }

        $readme = "Esportazione fatture Lamèss\r\nPeriodo: {$from} – {$until}\r\nFatture: {$invoices->count()} · Note di credito: {$notes->count()}"
            .($skipped > 0 ? "\r\nRicevute di privati escluse (non sono fatture): {$skipped}" : '')
            ."\r\n\r\nxml/            gli XML FatturaPA dei documenti (gli stessi trasmessi allo SdI)\r\nriepilogo.csv   una riga per documento e aliquota IVA; le note di credito hanno importi negativi\r\n"
            .($errors ? "\r\nDocumenti senza XML (dati da correggere):\r\n- ".implode("\r\n- ", $errors)."\r\n" : '');

        return ['zip' => $this->zip($files, $this->csv($rows), $readme), 'invoices' => $invoices->count(), 'credit_notes' => $notes->count(), 'receipts_skipped' => $skipped, 'errors' => $errors];
    }

    private const HEADER = ['Tipo documento', 'Numero', 'Data', 'Fattura collegata', 'Cliente', 'Codice fiscale', 'Partita IVA', 'Paese', 'Aliquota IVA', 'Natura', 'Imponibile', 'Imposta', 'Totale', 'Pagamento', 'Ordine', 'File XML', 'Esito'];

    /** @return array<int, array<int, string>> */
    private function rowsFor(Invoice|CreditNote $doc, string $filename, string $status): array
    {
        $credit = $doc instanceof CreditNote;
        $doc->loadMissing('lines');
        $buyer = (array) ($credit ? ($doc->invoice->buyer ?? []) : ($doc->buyer ?? []));
        $sign = $credit ? '-' : '';
        $totals = InvoiceService::totals($doc->lines->map(fn ($l) => ['net' => (string) $l->net, 'rate' => (string) $l->vat_rate]));
        $name = ($buyer['type'] ?? 'private') === 'company'
            ? (trim((string) ($buyer['company_name'] ?? '')) ?: trim((string) ($buyer['name'] ?? '')))
            : (trim((string) ($buyer['first_name'] ?? '').' '.(string) ($buyer['last_name'] ?? '')) ?: trim((string) ($buyer['name'] ?? '')));
        $method = match ($doc->order?->payment_method) {
            'card' => 'Carta', 'transfer' => 'Bonifico', 'kmoney' => 'KMoney', default => (string) ($doc->order?->payment_method ?? ''),
        };
        $out = [];
        foreach ($totals['by_rate'] as $rate => $x) {
            $zero = (float) $rate === 0.0;
            $total = (string) Num::money(Num::of($x['net'])->plus($x['vat']));
            $out[] = [
                $credit ? 'TD04' : 'TD01', (string) $doc->number, $doc->issued_at->setTimezone('Europe/Rome')->format('d/m/Y'),
                $credit ? (string) $doc->invoice->number : '', $name, strtoupper((string) ($buyer['tax_code'] ?? '')), strtoupper((string) ($buyer['vat_number'] ?? '')),
                FatturaPaBuilder::country($buyer), self::dec($rate), $zero ? FatturaPaBuilder::nature($buyer) : '',
                self::dec($sign.$x['net']), self::dec($sign.$x['vat']), self::dec($sign.$total), $method, (string) ($doc->order?->number ?? ''), $filename, $status,
            ];
        }

        return $out;
    }

    /** Importo con la virgola decimale, come lo vuole Excel in italiano. */
    private static function dec(string $v): string
    {
        return str_replace('.', ',', $v === '-0.00' ? '0.00' : $v);
    }

    /** @param  array<int, array<int, string>>  $rows */
    private function csv(array $rows): string
    {
        $h = fopen('php://temp', 'w+');
        foreach ($rows as $r) {
            // niente formule: una cella che inizia con = + - @ viene letta da Excel come calcolo (le cifre negative restano com'erano)
            fputcsv($h, array_map(fn (string $c) => preg_match('/^[=+@]/', $c) === 1 || (preg_match('/^-/', $c) === 1 && preg_match('/^-\d+(,\d+)?$/', $c) !== 1) ? "'".$c : $c, $r), ';', '"', '');
        }
        rewind($h);
        $csv = (string) stream_get_contents($h);
        fclose($h);

        return "\xEF\xBB\xBF".str_replace("\n", "\r\n", $csv);
    }

    /** @param  array<string, string>  $xml  nome file => contenuto */
    private function zip(array $xml, string $csv, string $readme): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lamess-fatture-');
        if ($path === false) {
            throw new RuntimeException('Impossibile creare il file temporaneo.');
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Impossibile creare lo ZIP.');
            }
            foreach ($xml as $name => $content) {
                $zip->addFromString('xml/'.$name, $content);
            }
            $zip->addFromString('riepilogo.csv', $csv);
            $zip->addFromString('LEGGIMI.txt', $readme);
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
