<?php

declare(strict_types=1);

namespace App\Filament\Resources\PvMovements\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\PvMovements\PvMovementResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditPvMovement extends SuperadminEditRecord
{
    protected static string $resource = PvMovementResource::class;
}
