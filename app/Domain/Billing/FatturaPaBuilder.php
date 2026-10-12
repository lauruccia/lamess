<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Engine\Support\Num;
use App\Models\CreditNote;
use App\Models\Invoice;
use XMLWriter;

/**
 * Fattura elettronica FatturaPA 1.2.2, formato FPR12 (tra privati, B2B e B2C): TD01 fattura, TD04 nota di credito.
 * Genera solo il file XML: la trasmissione la fa il sink (cartella, email/PEC, gestionale).
 */
final class FatturaPaBuilder
{
    /** @return array{filename: string, xml: string} */
    public function build(Invoice|CreditNote $doc): array
    {
        $isCredit = $doc instanceof CreditNote;
        if (! $isCredit && $doc->type !== 'invoice') {
            throw new BillingException('Il documento è una ricevuta, non una fattura: non si trasmette allo SdI.');
        }
        $doc->loadMissing('lines');
        if ($doc->lines->isEmpty()) {
            throw new BillingException('Il documento non ha righe.');
        }
        $seller = $this->seller();
        $buyer = $isCredit ? ($doc->invoice->buyer ?? []) : ($doc->buyer ?? []);
        $progressive = ($isCredit ? 'N' : 'F').strtoupper(str_pad(base_convert((string) $doc->id, 10, 36), 4, '0', STR_PAD_LEFT));
        $rows = $doc->lines->map(fn ($l) => ['net' => (string) $l->net, 'rate' => (string) $l->vat_rate]);
        $totals = InvoiceService::totals($rows);
        $grand = (string) Num::money(Num::of($totals['net'])->plus($totals['vat']));

        $w = new XMLWriter;
        $w->openMemory();
        $w->setIndent(true);
        $w->startDocument('1.0', 'UTF-8');
        $w->startElementNs('p', 'FatturaElettronica', 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2');
        $w->writeAttribute('versione', 'FPR12');
        $w->writeAttributeNs('xmlns', 'ds', null, 'http://www.w3.org/2000/09/xmldsig#');
        $w->writeAttributeNs('xmlns', 'xsi', null, 'http://www.w3.org/2001/XMLSchema-instance');
        $w->writeAttributeNs('xsi', 'schemaLocation', null, 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2 http://www.fatturapa.gov.it/export/fatturazione/sdi/fatturapa/v1.2/Schema_del_file_xml_FatturaPA_versione_1.2.xsd');

        $w->startElement('FatturaElettronicaHeader');
        $this->transmission($w, $seller, $buyer, $progressive);
        $this->seller_block($w, $seller);
        $this->buyer_block($w, $buyer);
        $w->endElement();

        $w->startElement('FatturaElettronicaBody');
        $w->startElement('DatiGenerali');
        $w->startElement('DatiGeneraliDocumento');
        $this->text($w, 'TipoDocumento', $isCredit ? 'TD04' : 'TD01');
        $this->text($w, 'Divisa', 'EUR');
        $this->text($w, 'Data', $doc->issued_at->setTimezone('Europe/Rome')->format('Y-m-d'));
        $this->text($w, 'Numero', $doc->number, 20);
        $this->text($w, 'ImportoTotaleDocumento', $grand);
        if ($isCredit && $doc->reason !== '') {
            $this->text($w, 'Causale', $doc->reason, 200);
        }
        $w->endElement();
        if ($isCredit) {
            $w->startElement('DatiFattureCollegate');
            $this->text($w, 'IdDocumento', $doc->invoice->number, 20);
            $this->text($w, 'Data', $doc->invoice->issued_at->setTimezone('Europe/Rome')->format('Y-m-d'));
            $w->endElement();
        }
        $w->endElement();

        $w->startElement('DatiBeniServizi');
        foreach ($doc->lines->values() as $i => $l) {
            $w->startElement('DettaglioLinee');
            $this->text($w, 'NumeroLinea', (string) ($i + 1));
            $this->text($w, 'Descrizione', (string) $l->description, 1000);
            $this->text($w, 'Quantita', number_format((float) $l->qty, 2, '.', ''));
            $this->text($w, 'PrezzoUnitario', $this->price((string) $l->unit_net));
            $this->text($w, 'PrezzoTotale', (string) $l->net);
            $this->text($w, 'AliquotaIVA', (string) $l->vat_rate);
            $w->endElement();
        }
        foreach ($totals['by_rate'] as $rate => $x) {
            $w->startElement('DatiRiepilogo');
            $this->text($w, 'AliquotaIVA', (string) $rate);
            $this->text($w, 'ImponibileImporto', $x['net']);
            $this->text($w, 'Imposta', $x['vat']);
            $this->text($w, 'EsigibilitaIVA', 'I');
            $w->endElement();
        }
        $w->endElement();

        if (! $isCredit) {
            $card = ($doc->order?->payment_method ?? 'card') !== 'transfer'; // carta e KMoney: pagamento elettronico (MP08)
            $w->startElement('DatiPagamento');
            $this->text($w, 'CondizioniPagamento', 'TP02');
            $w->startElement('DettaglioPagamento');
            $this->text($w, 'ModalitaPagamento', $card ? 'MP08' : 'MP05');
            $this->text($w, 'ImportoPagamento', $grand);
            $w->endElement();
            $w->endElement();
        }
        $w->endElement();
        $w->endElement();
        $w->endDocument();

        return ['filename' => "IT{$seller['vat']}_{$progressive}.xml", 'xml' => $w->outputMemory()];
    }

    /** Prezzo unitario con almeno 2 e al massimo 8 decimali. */
    private function price(string $v): string
    {
        $s = rtrim(number_format((float) $v, 8, '.', ''), '0');
        $dec = strlen(substr($s, (int) strpos($s, '.') + 1));

        return $dec >= 2 ? $s : number_format((float) $v, 2, '.', '');
    }

    /** @return array{name:string,vat:string,tax_code:string,address:string,zip:string,city:string,province:string,regime:string} */
    private function seller(): array
    {
        $c = (array) config('lamess.seller');
        $vat = preg_replace('/\D/', '', (string) ($c['vat_number'] ?? '')) ?? '';
        $zip = preg_replace('/\D/', '', (string) ($c['zip'] ?? '')) ?? '';
        $problems = [];
        if (strlen($vat) !== 11) {
            $problems[] = 'partita IVA (11 cifre)';
        }
        if (trim((string) ($c['name'] ?? '')) === '' || str_contains((string) $c['name'], 'da configurare')) {
            $problems[] = 'denominazione';
        }
        if (trim((string) ($c['address'] ?? '')) === '') {
            $problems[] = 'indirizzo';
        }
        if (strlen($zip) !== 5) {
            $problems[] = 'CAP';
        }
        if (trim((string) ($c['city'] ?? '')) === '') {
            $problems[] = 'comune';
        }
        if (strlen(trim((string) ($c['province'] ?? ''))) !== 2) {
            $problems[] = 'sigla provincia';
        }
        if ($problems !== []) {
            throw new BillingException('Dati del venditore incompleti per la fattura elettronica: '.implode(', ', $problems).'.');
        }

        return [
            'name' => (string) $c['name'], 'vat' => $vat, 'tax_code' => strtoupper(trim((string) ($c['tax_code'] ?? ''))), 'address' => (string) $c['address'],
            'zip' => $zip, 'city' => (string) $c['city'], 'province' => strtoupper(trim((string) $c['province'])), 'regime' => (string) ($c['regime'] ?: 'RF01'),
        ];
    }

    private function transmission(XMLWriter $w, array $seller, array $buyer, string $progressive): void
    {
        $sdi = strtoupper(trim((string) ($buyer['sdi_code'] ?? '')));
        $sdi = preg_match('/^[A-Z0-9]{7}$/', $sdi) === 1 ? $sdi : '0000000';
        $w->startElement('DatiTrasmissione');
        $w->startElement('IdTrasmittente');
        $this->text($w, 'IdPaese', 'IT');
        $this->text($w, 'IdCodice', $seller['tax_code'] !== '' ? $seller['tax_code'] : $seller['vat']);
        $w->endElement();
        $this->text($w, 'ProgressivoInvio', $progressive);
        $this->text($w, 'FormatoTrasmissione', 'FPR12');
        $this->text($w, 'CodiceDestinatario', $sdi);
        if ($sdi === '0000000' && filled($buyer['pec'] ?? null)) {
            $this->text($w, 'PECDestinatario', (string) $buyer['pec'], 256);
        }
        $w->endElement();
    }

    private function seller_block(XMLWriter $w, array $s): void
    {
        $w->startElement('CedentePrestatore');
        $w->startElement('DatiAnagrafici');
        $w->startElement('IdFiscaleIVA');
        $this->text($w, 'IdPaese', 'IT');
        $this->text($w, 'IdCodice', $s['vat']);
        $w->endElement();
        if ($s['tax_code'] !== '') {
            $this->text($w, 'CodiceFiscale', $s['tax_code']);
        }
        $w->startElement('Anagrafica');
        $this->text($w, 'Denominazione', $s['name'], 80);
        $w->endElement();
        $this->text($w, 'RegimeFiscale', $s['regime']);
        $w->endElement();
        $this->address($w, $s['address'], $s['zip'], $s['city'], $s['province']);
        $w->endElement();
    }

    private function buyer_block(XMLWriter $w, array $b): void
    {
        $company = ($b['type'] ?? 'private') === 'company';
        $vat = preg_replace('/^IT/i', '', preg_replace('/\s/', '', (string) ($b['vat_number'] ?? '')) ?? '') ?? '';
        $cf = strtoupper(preg_replace('/\s/', '', (string) ($b['tax_code'] ?? '')) ?? '');
        if ($vat === '' && $cf === '') {
            throw new BillingException('Cliente senza codice fiscale né partita IVA: la fattura elettronica non è trasmissibile.');
        }
        $zip = preg_replace('/\D/', '', (string) ($b['zip'] ?? '')) ?? '';
        if (trim((string) ($b['address'] ?? '')) === '' || strlen($zip) !== 5 || trim((string) ($b['city'] ?? '')) === '') {
            throw new BillingException('Indirizzo del cliente incompleto (via, CAP, comune).');
        }
        $w->startElement('CessionarioCommittente');
        $w->startElement('DatiAnagrafici');
        if ($vat !== '') {
            $w->startElement('IdFiscaleIVA');
            $this->text($w, 'IdPaese', 'IT');
            $this->text($w, 'IdCodice', $vat);
            $w->endElement();
        }
        if ($cf !== '') {
            $this->text($w, 'CodiceFiscale', $cf);
        }
        $w->startElement('Anagrafica');
        $name = trim((string) ($b['name'] ?? ''));
        if ($company) {
            $denomination = trim((string) ($b['company_name'] ?? '')) ?: $name;
            if ($denomination === '') {
                throw new BillingException('Cliente senza ragione sociale.');
            }
            $this->text($w, 'Denominazione', $denomination, 80);
        } else {
            $first = trim((string) ($b['first_name'] ?? ''));
            $last = trim((string) ($b['last_name'] ?? ''));
            if ($first === '' || $last === '') {
                // fatture emesse prima che l'ordine conservasse nome e cognome separati: si divide «Nome Cognome»
                [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');
            }
            if (trim($first) === '' || trim($last) === '') {
                throw new BillingException('Cliente senza nome e cognome (o ragione sociale).');
            }
            $this->text($w, 'Nome', $first, 60);
            $this->text($w, 'Cognome', $last, 60);
        }
        $w->endElement();
        $w->endElement();
        $this->address($w, (string) $b['address'], $zip, (string) $b['city'], strtoupper(trim((string) ($b['province'] ?? ''))));
        $w->endElement();
    }

    private function address(XMLWriter $w, string $street, string $zip, string $city, string $province): void
    {
        $w->startElement('Sede');
        $this->text($w, 'Indirizzo', $street, 60);
        $this->text($w, 'CAP', $zip);
        $this->text($w, 'Comune', $city, 60);
        if (strlen($province) === 2) {
            $this->text($w, 'Provincia', $province);
        }
        $this->text($w, 'Nazione', 'IT');
        $w->endElement();
    }

    /** Il tracciato ammette solo caratteri Latin-1: gli altri diventano «?». */
    private function text(XMLWriter $w, string $name, string $value, ?int $max = null): void
    {
        $clean = preg_replace('/[^\x{0020}-\x{007E}\x{00A0}-\x{00FF}]/u', '?', trim(preg_replace('/\s+/', ' ', $value) ?? '')) ?? '';
        $w->writeElement($name, $max !== null ? mb_substr($clean, 0, $max) : $clean);
    }
}
