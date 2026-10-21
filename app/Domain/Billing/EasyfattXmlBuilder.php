<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\OrderItem;
use Carbon\CarbonImmutable;
use XMLWriter;

/**
 * File «Easyfatt-Xml» (Danea Easyfatt: Utilità → Importa documenti Easyfatt-Xml) con le fatture e le note di credito del portale.
 * Non è la fattura elettronica per lo SdI (quella è FatturaPaBuilder): serve solo a registrare i documenti nel gestionale.
 * Prezzi IVA esclusa, come nel portale; i totali scritti nel file sono indicativi, Easyfatt li ricalcola dalle righe.
 */
final class EasyfattXmlBuilder
{
    /**
     * Fatture e note di credito emesse tra due date (giorni di calendario italiani, estremi inclusi).
     * Le ricevute dei privati non sono fatture: restano fuori, a meno che non si chiedano (allora entrano come fattura).
     *
     * @return array{xml: string, count: int, invoices: int, credit_notes: int, receipts_skipped: int}
     */
    public function forPeriod(string $from, string $until, bool $includeReceipts = false): array
    {
        $start = CarbonImmutable::parse($from, 'Europe/Rome')->startOfDay()->setTimezone((string) config('app.timezone'));
        $end = CarbonImmutable::parse($until, 'Europe/Rome')->endOfDay()->setTimezone((string) config('app.timezone'));

        $invoices = Invoice::whereBetween('issued_at', [$start, $end])->orderBy('issued_at')->orderBy('id')->get();
        $skipped = $includeReceipts ? 0 : $invoices->where('type', '!=', 'invoice')->count();
        $invoices = $includeReceipts ? $invoices : $invoices->where('type', 'invoice')->values();

        $notes = CreditNote::with('invoice')->whereBetween('issued_at', [$start, $end])->orderBy('issued_at')->orderBy('id')->get();
        $notes = $includeReceipts ? $notes : $notes->filter(fn (CreditNote $n) => $n->invoice?->type === 'invoice')->values();

        $built = $this->build($invoices->concat($notes));

        return $built + ['invoices' => $invoices->count(), 'credit_notes' => $notes->count(), 'receipts_skipped' => $skipped];
    }

    /**
     * @param  iterable<Invoice|CreditNote>  $docs
     * @return array{xml: string, count: int}
     */
    public function build(iterable $docs): array
    {
        $docs = collect($docs)->values();
        $docs->each(fn (Invoice|CreditNote $d) => $d->loadMissing('lines', 'order'));
        $docs->each(fn (Invoice|CreditNote $d) => $d instanceof CreditNote ? $d->loadMissing('invoice') : null);
        $skus = OrderItem::whereIn('id', $docs->flatMap(fn ($d) => $d->lines->pluck('order_item_id'))->filter()->unique()->all())->pluck('sku', 'id');

        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElement('EasyfattDocuments');
        $w->writeAttribute('AppVersion', '2');
        $w->writeAttribute('Creator', 'Portale Lamèss');
        $w->writeAttribute('CreatorUrl', (string) config('app.url'));
        $w->startElement('Documents');
        foreach ($docs as $doc) {
            $this->document($w, $doc, $skus->all());
        }
        $w->endElement();
        $w->endElement();
        $w->endDocument();

        return ['xml' => $w->outputMemory(), 'count' => $docs->count()];
    }

    /** @param array<int,string> $skus */
    private function document(XMLWriter $w, Invoice|CreditNote $doc, array $skus): void
    {
        $isCredit = $doc instanceof CreditNote;
        $buyer = (array) ($isCredit ? ($doc->invoice?->buyer ?? $doc->buyer ?? []) : ($doc->buyer ?? []));
        $order = $doc->order;
        $country = FatturaPaBuilder::country($buyer);
        $company = ($buyer['type'] ?? 'private') === 'company';
        $name = $company ? (trim((string) ($buyer['company_name'] ?? '')) ?: trim((string) ($buyer['name'] ?? ''))) : trim(trim((string) ($buyer['first_name'] ?? '')).' '.trim((string) ($buyer['last_name'] ?? '')));
        $name = $name !== '' ? $name : trim((string) ($buyer['name'] ?? ''));

        $w->startElement('Document');
        $this->put($w, 'DocumentType', $isCredit ? 'N' : 'I');
        $this->put($w, 'Date', $doc->issued_at->setTimezone('Europe/Rome')->format('Y-m-d'));
        $this->put($w, 'Number', (string) self::progressive((string) $doc->number));
        $this->put($w, 'Numbering', self::section((string) $doc->number));

        $this->put($w, 'CustomerName', $name);
        $this->put($w, 'CustomerAddress', (string) ($buyer['address'] ?? ''));
        $this->put($w, 'CustomerPostcode', (string) ($buyer['zip'] ?? ''));
        $this->put($w, 'CustomerCity', (string) ($buyer['city'] ?? ''));
        $this->put($w, 'CustomerProvince', $country === 'IT' ? strtoupper(substr(trim((string) ($buyer['province'] ?? '')), 0, 2)) : '');
        $this->put($w, 'CustomerCountry', $country === 'IT' ? '' : (string) (config('lamess.country_names')[$country] ?? $country));
        $this->put($w, 'CustomerFiscalCode', strtoupper((string) preg_replace('/\s/', '', (string) ($buyer['tax_code'] ?? ''))));
        $this->put($w, 'CustomerVatCode', self::vatCode((string) ($buyer['vat_number'] ?? ''), $country));
        $this->put($w, 'CustomerEInvoiceDestCode', self::destinationCode($buyer, $country));
        $this->put($w, 'CustomerEmail', (string) ($buyer['email'] ?? ''));
        $this->put($w, 'CustomerPec', (string) ($buyer['pec'] ?? ''));

        $this->delivery($w, $buyer, $order?->country_code ? strtoupper((string) $order->country_code) : $country, $name);

        if ($order !== null) {
            $this->put($w, 'Carrier', (string) ($order->carrier ?? ''));
            $this->put($w, 'TrackingNumber', (string) ($order->tracking_code ?? ''));
        }
        $this->put($w, 'InternalComment', self::comment($doc, $order?->number));
        $this->put($w, 'TotalWithoutTax', (string) $doc->net);
        $this->put($w, 'VatAmount', (string) $doc->vat);
        $this->put($w, 'Total', (string) $doc->total);
        $this->put($w, 'PricesIncludeVat', 'false');

        $w->startElement('Rows');
        foreach ($doc->lines as $line) {
            $this->row($w, $line, $skus[$line->order_item_id] ?? '', FatturaPaBuilder::nature($buyer));
        }
        $w->endElement();

        if (! $isCredit) {
            $paidAt = $order?->paid_at ?? $doc->issued_at;
            $w->startElement('Payments');
            $w->startElement('Payment');
            $this->put($w, 'Advance', 'false');
            $this->put($w, 'Date', $paidAt->setTimezone('Europe/Rome')->format('Y-m-d'));
            $this->put($w, 'Amount', (string) $doc->total);
            $this->put($w, 'Paid', $order?->paid_at !== null ? 'true' : 'false');
            $w->endElement();
            $w->endElement();
        }
        $w->endElement();
    }

    private function row(XMLWriter $w, object $line, string $sku, string $nature): void
    {
        $rate = (float) $line->vat_rate;
        $zero = $rate == 0.0;
        $w->startElement('Row');
        $this->put($w, 'Code', $sku);
        $this->put($w, 'Description', (string) $line->description);
        $this->put($w, 'Qty', (string) $line->qty);
        $this->put($w, 'Price', self::price((string) $line->unit_net));
        $w->startElement('VatCode');
        $w->writeAttribute('Perc', number_format($rate, 2, '.', ''));
        $w->writeAttribute('Class', $zero ? self::vatClass($nature) : 'Imponibile');
        $w->writeAttribute('Description', $zero ? FatturaPaBuilder::natureReference($nature) : 'Aliquota '.rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.').'%');
        $w->text($zero ? $nature : rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'));
        $w->endElement();
        $w->endElement();
    }

    /** Destinazione solo se diversa dalla fatturazione. */
    private function delivery(XMLWriter $w, array $buyer, string $country, string $name): void
    {
        $s = (array) ($buyer['shipping'] ?? []);
        $same = fn (string $k) => mb_strtolower(trim((string) ($s[$k] ?? ''))) === mb_strtolower(trim((string) ($buyer[$k] ?? '')));
        if ($s === [] || trim((string) ($s['address'] ?? '')) === '' || ($same('address') && $same('city') && $same('zip'))) {
            return;
        }
        $this->put($w, 'DeliveryName', trim((string) ($s['recipient'] ?? '')) ?: $name);
        $this->put($w, 'DeliveryAddress', (string) $s['address']);
        $this->put($w, 'DeliveryPostcode', (string) ($s['zip'] ?? ''));
        $this->put($w, 'DeliveryCity', (string) ($s['city'] ?? ''));
        $this->put($w, 'DeliveryProvince', $country === 'IT' ? strtoupper(substr(trim((string) ($s['province'] ?? '')), 0, 2)) : '');
        $this->put($w, 'DeliveryCountry', $country === 'IT' ? '' : (string) (config('lamess.country_names')[$country] ?? $country));
    }

    /** «FT/2026/00234» → 234; «2026/pri12» → 12. */
    public static function progressive(string $number): int
    {
        return preg_match('/(\d+)\s*$/', $number, $m) === 1 ? (int) $m[1] : 0;
    }

    /** Sezionale di Easyfatt: le lettere del numero del portale («FT/2026/00234» → «/FT»), così non si mescola con la numerazione del gestionale. */
    public static function section(string $number): string
    {
        $letters = (string) preg_replace('/[^A-Za-z]/', '', $number);

        return $letters !== '' ? '/'.strtoupper($letters) : '';
    }

    private static function comment(Invoice|CreditNote $doc, ?string $orderNumber): string
    {
        $parts = ['Portale Lamèss: '.($doc instanceof CreditNote ? 'nota di credito ' : 'fattura ').$doc->number];
        if ($doc instanceof CreditNote && $doc->invoice !== null) {
            $parts[] = 'storna la fattura '.$doc->invoice->number;
        }
        if (filled($orderNumber)) {
            $parts[] = 'ordine '.$orderNumber;
        }

        return implode(' - ', $parts);
    }

    private static function vatCode(string $vat, string $country): string
    {
        $v = strtoupper((string) preg_replace('/\s|[.\-]/', '', $vat));

        return $country === 'IT' ? (string) preg_replace('/^IT(?=\d{11}$)/', '', $v) : $v;
    }

    private static function destinationCode(array $buyer, string $country): string
    {
        if ($country !== 'IT') {
            return 'XXXXXXX';
        }
        $sdi = strtoupper(trim((string) ($buyer['sdi_code'] ?? '')));

        return preg_match('/^[A-Z0-9]{7}$/', $sdi) === 1 ? $sdi : '';
    }

    /** Classe IVA di Easyfatt per le righe a IVA 0, in base alla natura FatturaPA. */
    private static function vatClass(string $nature): string
    {
        return match (true) {
            $nature === 'N3.1' => 'Extra-UE',
            $nature === 'N3.2' => 'Intra-UE',
            str_starts_with($nature, 'N1') => 'Escluso',
            str_starts_with($nature, 'N2') => 'Fuori campo',
            str_starts_with($nature, 'N4') => 'Esente',
            str_starts_with($nature, 'N6') => 'Rev. charge',
            default => 'Non imponibile',
        };
    }

    /** Prezzo unitario con almeno 2 e al massimo 8 decimali (come nella fattura elettronica). */
    private static function price(string $v): string
    {
        $s = rtrim(number_format((float) $v, 8, '.', ''), '0');
        $dec = strlen(substr($s, (int) strpos($s, '.') + 1));

        return $dec >= 2 ? $s : number_format((float) $v, 2, '.', '');
    }

    /** Scrive il tag solo se ha un valore (come fa Easyfatt in esportazione). */
    private function put(XMLWriter $w, string $tag, string $value): void
    {
        $clean = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', preg_replace('/\s+/', ' ', $value) ?? ''));
        if ($clean !== '') {
            $w->writeElement($tag, $clean);
        }
    }
}
