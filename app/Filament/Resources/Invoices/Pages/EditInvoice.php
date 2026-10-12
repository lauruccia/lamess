<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Invoices\InvoiceResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditInvoice extends SuperadminEditRecord
{
    protected static string $resource = InvoiceResource::class;
}
