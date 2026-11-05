<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\Actor;
use App\Domain\Features\Feature;
use App\Domain\Network\ConsultantConversion;
use App\Domain\Network\NetworkException;
use App\Models\Consultant;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/** «Fai diventare cliente»: l'incaricato torna solo cliente (declassato se senza storico, altrimenti cessato). Stesso gesto in lista e nella scheda. */
final class MakeCustomerAction
{
    public static function forRow(?callable $after = null): Action
    {
        return Action::make('makeCustomer')->authorize('network.manage')
            ->label('Fai diventare cliente')->icon('heroicon-o-arrow-uturn-down')->color('danger')
            ->visible(fn (Consultant $record): bool => $record->status !== 'terminated' && $record->user_id !== null && Feature::enabled('consultant360.action.make_customer'))
            ->modalHeading(fn (Consultant $record): string => "Fai diventare cliente {$record->name}")
            ->modalDescription(fn (Consultant $record): string => app(ConsultantConversion::class)->history($record) === []
                ? 'Non ha storico economico: esce dall\'elenco e dall\'albero, i suoi diretti e i suoi clienti passano al suo sponsor, e la persona resta cliente.'
                : 'Ha già storico (ordini, commissioni, ecc.): l\'incarico viene cessato e nulla si cancella. I suoi diretti salgono al primo ascendente in essere (Reg. 2.8), la persona perde il ruolo di incaricato e resta cliente. L\'operazione non si annulla.')
            ->modalSubmitActionLabel('Fai diventare cliente')
            ->schema([Textarea::make('reason')->label('Motivazione')->required()])
            ->action(function (Consultant $record, array $data) use ($after): void {
                try {
                    $mode = app(ConsultantConversion::class)->makeCustomer($record, (string) $data['reason'], Actor::label());
                    Notification::make()->title("{$record->name} è ora cliente")
                        ->body($mode === 'removed' ? 'Tolto dall\'elenco incaricati e dall\'albero.' : 'Incarico cessato: resta nello storico come cessato.')->success()->send();
                    if ($after !== null) {
                        $after($mode);
                    }
                } catch (NetworkException $e) {
                    Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                }
            });
    }
}
