<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;

/** Driver di sviluppo: accetta tutto e restituisce un riferimento finto. NON invia nulla all'SDI. */
final class FakeInvoiceSink implements InvoiceSink
{
    public function name(): string
    {
        return 'fake';
    }

    public function send(Invoice|CreditNote $document): SinkResult
    {
        return new SinkResult(true, 'FAKE-'.str_replace('/', '-', $document->number));
    }
}
