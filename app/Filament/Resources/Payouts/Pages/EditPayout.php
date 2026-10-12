<?php

declare(strict_types=1);

namespace App\Filament\Resources\Payouts\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Payouts\PayoutResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditPayout extends SuperadminEditRecord
{
    protected static string $resource = PayoutResource::class;
}
