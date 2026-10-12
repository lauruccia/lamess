<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorporateEvents\Pages;

use App\Filament\Resources\CorporateEvents\CorporateEventResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCorporateEvent extends CreateRecord
{
    protected static string $resource = CorporateEventResource::class;
}
