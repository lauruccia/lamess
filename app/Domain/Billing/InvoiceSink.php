<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\CreditNote;
use App\Models\Invoice;

/**
 * Destinazione dei documenti fiscali emessi (gestionale/SDI). Il portale emette e numera;
 * il sink consegna il documento al sistema che lo trasmette (GestionaleK, provider SDI…).
 */
interface InvoiceSink
{
    public function name(): string;

    public function send(Invoice|CreditNote $document): SinkResult;
}
