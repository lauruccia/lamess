<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListCustomers extends SuperadminListRecords
{
    protected static string $resource = CustomerResource::class;
}
