<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShippingRates\Pages;

use App\Filament\Resources\ShippingRates\ShippingRateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateShippingRate extends CreateRecord
{
    protected static string $resource = ShippingRateResource::class;
}
