<?php

declare(strict_types=1);

namespace App\Filament\Resources\CorporateEvents\Pages;

use App\Filament\Resources\CorporateEvents\CorporateEventResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCorporateEvent extends EditRecord
{
    protected static string $resource = CorporateEventResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
