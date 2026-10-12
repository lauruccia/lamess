<?php

declare(strict_types=1);

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListInvoices extends SuperadminListRecords
{
    protected static string $resource = InvoiceResource::class;
}
