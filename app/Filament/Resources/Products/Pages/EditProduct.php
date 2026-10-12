<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')->label('Vedi nel negozio')->icon('heroicon-o-arrow-top-right-on-square')->color('gray')
                ->url(fn () => url('/negozio/'.$this->getRecord()->slug), shouldOpenInNewTab: true)
                ->visible(fn () => $this->getRecord()->active),
            DeleteAction::make()->visible(fn () => $this->getRecord()->isDeletable())
                ->modalDescription('Il prodotto non è mai stato venduto: si può eliminare. Altrimenti nascondilo.'),
        ];
    }
}
