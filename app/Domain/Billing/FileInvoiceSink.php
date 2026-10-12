<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Scrive l'XML FatturaPA in una cartella (storage/app/private/sdi-out) pronta per essere ritirata
 * dal gestionale/commercialista o caricata sul portale SdI. Non trasmette nulla da sola.
 */
class FileInvoiceSink implements InvoiceSink
{
    public function __construct(protected readonly FatturaPaBuilder $builder) {}

    public function name(): string
    {
        return 'file';
    }

    public function send(Invoice|CreditNote $document): SinkResult
    {
        try {
            return $this->store($document)['result'];
        } catch (BillingException $e) {
            return new SinkResult(false, null, $e->getMessage());
        } catch (Throwable $e) {
            return new SinkResult(false, null, 'Scrittura del file non riuscita: '.$e->getMessage());
        }
    }

    /** @return array{result: SinkResult, filename: string, xml: string} */
    protected function store(Invoice|CreditNote $document): array
    {
        if ($document instanceof Invoice && $document->type !== 'invoice') {
            return ['result' => new SinkResult(true, 'ricevuta-non-trasmessa'), 'filename' => '', 'xml' => ''];
        }
        ['filename' => $filename, 'xml' => $xml] = $this->builder->build($document);
        Storage::put('sdi-out/'.$filename, $xml);

        return ['result' => new SinkResult(true, $filename), 'filename' => $filename, 'xml' => $xml];
    }
}
