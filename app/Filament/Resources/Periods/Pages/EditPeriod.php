<?php

declare(strict_types=1);

namespace App\Filament\Resources\Periods\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Periods\PeriodResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditPeriod extends SuperadminEditRecord
{
    protected static string $resource = PeriodResource::class;
}
