<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletTransactions\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\WalletTransactions\WalletTransactionResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditWalletTransaction extends SuperadminEditRecord
{
    protected static string $resource = WalletTransactionResource::class;
}
