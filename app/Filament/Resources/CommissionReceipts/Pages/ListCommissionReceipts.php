<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommissionReceipts\Pages;

use App\Filament\Resources\CommissionReceipts\CommissionReceiptResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListCommissionReceipts extends SuperadminListRecords
{
    protected static string $resource = CommissionReceiptResource::class;
}
