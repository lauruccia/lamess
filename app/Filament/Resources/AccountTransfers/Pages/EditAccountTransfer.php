<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccountTransfers\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\AccountTransfers\AccountTransferResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditAccountTransfer extends SuperadminEditRecord
{
    protected static string $resource = AccountTransferResource::class;
}
