<?php

declare(strict_types=1);

namespace App\Filament\Resources\PointGrants\Pages;

use App\Filament\Resources\PointGrants\PointGrantResource;
use App\Filament\Support\GrantPointsAction;
use Filament\Resources\Pages\ListRecords;

class ListPointGrants extends ListRecords
{
    protected static string $resource = PointGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [GrantPointsAction::make()];
    }
}
