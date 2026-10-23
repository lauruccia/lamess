<?php

declare(strict_types=1);

namespace App\Filament\Resources\RankHelps\Pages;

use App\Filament\Resources\RankHelps\RankHelpResource;
use App\Filament\Support\GrantPointsAction;
use Filament\Resources\Pages\ListRecords;

class ListRankHelps extends ListRecords
{
    protected static string $resource = RankHelpResource::class;

    protected function getHeaderActions(): array
    {
        return [GrantPointsAction::make()];
    }
}
