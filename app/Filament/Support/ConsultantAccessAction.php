<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Network\ConsultantAccess;
use App\Domain\Network\NetworkException;
use App\Models\Consultant;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/** «Crea accesso»: l'incaricato creato dall'admin entra da /accedi con un utente collegato alla sua scheda. */
final class ConsultantAccessAction
{
    /** @return list<Action> azioni per la scheda dell'incaricato: `$record` restituisce l'incaricato della pagina. */
    public static function forPage(callable $record): array
    {
        return [
            self::grant()
                ->visible(fn (): bool => $record()->user_id === null && $record()->status !== 'terminated')
                ->action(fn (array $data) => self::runGrant($record(), (string) $data['email'])),
        ];
    }

    /** @return list<Action> azioni per la riga dell'elenco. */
    public static function forRow(): array
    {
        return [
            self::grant()
                ->visible(fn (Consultant $record): bool => $record->user_id === null && $record->status !== 'terminated')
                ->action(fn (array $data, Consultant $record) => self::runGrant($record, (string) $data['email'])),
        ];
    }

    private static function grant(): Action
    {
        return Action::make('grantAccess')->authorize('network.manage')->label('Crea accesso')->icon('heroicon-o-key')->color('gray')
            ->modalHeading('Crea l’accesso al portale')
            ->modalDescription('Crea l’utente con questa email e lo collega alla scheda. Se l’invio email è attivo, l’incaricato riceve il link per scegliere la password. Se esiste già un utente con quell’email (es. un cliente), viene collegato alla scheda.')
            ->schema([TextInput::make('email')->label('Email dell’incaricato')->email()->trim()->required()]);
    }

    public static function runGrant(Consultant $consultant, string $email): void
    {
        try {
            $access = app(ConsultantAccess::class);
            $user = $access->grant($consultant, $email);
        } catch (NetworkException $e) {
            Notification::make()->title("{$consultant->code}: accesso non creato")->body($e->getMessage())->danger()->send();

            return;
        }
        self::notifyOutcome($user->email, $access->inviteSent);
    }

    /** Esito per l'admin: invito partito, oppure accesso creato ma email spente/non riuscite (il link si manda poi con «Invia reset password»). */
    public static function notifyOutcome(string $email, bool $inviteSent): void
    {
        if ($inviteSent) {
            Notification::make()->title('Accesso creato')->body("Invito inviato a {$email}. Se non arriva, usa «Invia reset password».")->success()->send();

            return;
        }
        Notification::make()->title('Accesso creato, invito non inviato')
            ->body("Utente collegato a {$email}, ma l'email non è partita (invio email spento o errore di posta). Quando l'invio è attivo manda il link con «Invia reset password».")->warning()->persistent()->send();
    }
}
