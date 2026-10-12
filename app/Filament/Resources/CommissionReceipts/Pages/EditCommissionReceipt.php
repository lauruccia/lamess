<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommissionReceipts\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\CommissionReceipts\CommissionReceiptResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditCommissionReceipt extends SuperadminEditRecord
{
    protected static string $resource = CommissionReceiptResource::class;
}
