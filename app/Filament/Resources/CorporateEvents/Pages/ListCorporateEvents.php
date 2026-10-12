<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorporateEvents\Pages;

use App\Filament\Resources\CorporateEvents\CorporateEventResource;
use Filament\Actions\CreateAction;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListCorporateEvents extends SuperadminListRecords
{
    protected static string $resource = CorporateEventResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
