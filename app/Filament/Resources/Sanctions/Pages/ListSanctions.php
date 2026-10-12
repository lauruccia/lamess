<?php

declare(strict_types=1);

namespace App\Filament\Resources\Sanctions\Pages;

use App\Filament\Resources\Sanctions\SanctionResource;
use App\Models\Consultant;
use Filament\Actions\Action;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListSanctions extends SuperadminListRecords
{
    protected static string $resource = SanctionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply')->authorize('compliance.manage')->label('Applica una sanzione')->icon('heroicon-o-exclamation-triangle')
                ->schema(SanctionResource::applyForm(true))
                ->action(fn (array $data) => SanctionResource::apply(Consultant::findOrFail($data['consultant_id']), $data)),
        ];
    }
}
