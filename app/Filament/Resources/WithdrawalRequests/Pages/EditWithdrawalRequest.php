<?php

declare(strict_types=1);

namespace App\Filament\Resources\WithdrawalRequests\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\WithdrawalRequests\WithdrawalRequestResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditWithdrawalRequest extends SuperadminEditRecord
{
    protected static string $resource = WithdrawalRequestResource::class;
}
