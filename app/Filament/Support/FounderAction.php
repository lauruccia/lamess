<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/** Segna o toglie lo stato «founder» a un incaricato: i founder comprano ai prezzi founder dei prodotti. */
final class FounderAction
{
    public static function forRow(): Action
    {
        return Action::make('toggleFounder')->authorize('network.restructure')
            ->label(fn (Consultant $record): string => $record->is_founder ? 'Togli lo stato founder' : 'Segna come founder')
            ->icon('heroicon-o-star')->color('gray')->requiresConfirmation()
            ->modalDescription(fn (Consultant $record): string => $record->is_founder
                ? 'Da ora l\'incaricato paga i prezzi di listino, anche negli abbonamenti già attivi.'
                : 'Da ora l\'incaricato paga i prezzi founder dei prodotti che ne hanno uno, anche negli abbonamenti già attivi. I punti non cambiano.')
            ->action(function (Consultant $record): void {
                $new = ! $record->is_founder;
                $record->update(['is_founder' => $new]);
                AuditLog::record('consultant.founder', ($new ? 'Segnato come founder: ' : 'Tolto lo stato founder: ').$record->name, $record, ['is_founder' => ['prima' => ! $new, 'dopo' => $new]]);
                Notification::make()->title($new ? 'Incaricato segnato come founder' : 'Stato founder tolto')->success()->send();
            });
    }
}
