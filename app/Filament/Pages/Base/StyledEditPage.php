<?php

declare(strict_types=1);

namespace App\Filament\Pages\Base;

use Filament\Actions\Action;

/** Aspetto comune delle pagine di modifica: pulsanti sempre visibili, «Salva modifiche», «Annulla» che torna all'elenco. */
trait StyledEditPage
{
    public function areFormActionsSticky(): bool
    {
        return true;
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->label('Salva modifiche');
    }

    protected function getCancelFormAction(): Action
    {
        return Action::make('cancel')->label('Annulla')->color('gray')->url(static::getResource()::getUrl('index'));
    }

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }
}
