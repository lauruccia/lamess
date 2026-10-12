<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromoMaterials\Pages;

use App\Domain\Compliance\MaterialService;
use App\Filament\Resources\PromoMaterials\PromoMaterialResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListPromoMaterials extends SuperadminListRecords
{
    protected static string $resource = PromoMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('official')->authorize('compliance.manage')->label('Pubblica materiale ufficiale')->icon('heroicon-o-plus')
                ->schema([
                    TextInput::make('title')->label('Titolo')->required()->maxLength(190),
                    Textarea::make('description')->label('A cosa serve'),
                    Textarea::make('claims')->label('Testo approvato')->rows(6),
                    TextInput::make('url')->label('Link')->url(),
                    DatePicker::make('valid_until')->label('Valido fino al'),
                ])
                ->action(function (array $data): void {
                    $m = app(MaterialService::class)->publishOfficial($data['title'], $data['description'] ?? null, $data['claims'] ?? null, $data['url'] ?? null, ! empty($data['valid_until']) ? CarbonImmutable::parse($data['valid_until']) : null);
                    Notification::make()->title("Pubblicato con il codice {$m->code}")->success()->send();
                }),
        ];
    }
}
