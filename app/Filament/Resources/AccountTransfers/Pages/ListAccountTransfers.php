<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountTransfers\Pages;

use App\Filament\Resources\AccountTransfers\AccountTransferResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListAccountTransfers extends SuperadminListRecords
{
    protected static string $resource = AccountTransferResource::class;
}
