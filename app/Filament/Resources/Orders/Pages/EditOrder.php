<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Orders\OrderResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditOrder extends SuperadminEditRecord
{
    protected static string $resource = OrderResource::class;
}
