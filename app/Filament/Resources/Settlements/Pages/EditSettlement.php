<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settlements\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Settlements\SettlementResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditSettlement extends SuperadminEditRecord
{
    protected static string $resource = SettlementResource::class;
}
