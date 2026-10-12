<?php

declare(strict_types=1);

namespace App\Filament\Resources\PvMovements\Pages;

use App\Filament\Resources\PvMovements\PvMovementResource;
use Filament\Actions\CreateAction;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListPvMovements extends SuperadminListRecords
{
    protected static string $resource = PvMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Registra movimento')];
    }
}
