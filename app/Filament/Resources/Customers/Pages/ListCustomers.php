<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Domain\Access\PasswordResetLinks;
use App\Filament\Pages\Base\SuperadminListRecords;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\PasswordResetAction;

class ListCustomers extends SuperadminListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [PasswordResetAction::all(PasswordResetLinks::AUDIENCE_CUSTOMERS)];
    }
}
