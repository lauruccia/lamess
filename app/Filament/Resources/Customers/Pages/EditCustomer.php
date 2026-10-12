<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Pages\Base\SuperadminEditRecord;
use App\Filament\Resources\Customers\CustomerResource;

/** Modifica diretta dei dati, riservata al super_admin. */
class EditCustomer extends SuperadminEditRecord
{
    protected static string $resource = CustomerResource::class;
}
