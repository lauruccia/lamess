<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\PasswordResetLinks;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * «Invia reset password»: l'admin manda il link per scegliere una nuova password a un account, a quelli selezionati
 * o a tutti (incaricati e clienti). Vale per gli elenchi di Incaricati, Clienti e Utenti: il record può essere un
 * incaricato, un cliente o un utente. La password non cambia finché la persona non usa il link.
 */
final class PasswordResetAction
{
    public const CONFIRM_WORD = 'INVIA';

    /** L'utente collegato al record dell'elenco (nessuna query: si usa la colonna, non la relazione). */
    private static function userId(Model $record): ?int
    {
        $id = $record instanceof User ? $record->getKey() : $record->getAttribute('user_id');

        return $id === null ? null : (int) $id;
    }

    /** Azione sulla riga dell'elenco. */
    public static function forRow(): Action
    {
        return Action::make('sendPasswordReset')->authorize('network.manage')->label('Invia reset password')->icon('heroicon-o-key')->color('gray')
            ->visible(fn (Model $record): bool => self::userId($record) !== null && ! ($record instanceof User && $record->isBackoffice()))
            ->requiresConfirmation()->modalHeading('Invia il link per scegliere una nuova password')
            ->modalDescription('La persona riceve una email con un link (valido per un tempo limitato). La password attuale resta valida finché non ne sceglie una nuova.')
            ->modalSubmitActionLabel('Invia')
            ->action(fn (Model $record) => self::run([self::userId($record)], false));
    }

    /** Azione sulle righe selezionate. */
    public static function bulk(): BulkAction
    {
        return BulkAction::make('sendPasswordResetBulk')->authorize('network.manage')->label('Invia reset password')->icon('heroicon-o-key')->color('gray')
            ->requiresConfirmation()->modalHeading('Invia il link per scegliere una nuova password')
            ->modalDescription('Lo stesso invio a tutte le persone selezionate. Le password attuali restano valide finché non ne scelgono una nuova.')
            ->modalSubmitActionLabel('Invia')
            ->deselectRecordsAfterCompletion()
            ->action(fn (Collection $records) => self::run($records->map(fn (Model $r): ?int => self::userId($r))->all(), false));
    }

    /** Pulsante in testata: invio a tutti gli incaricati, a tutti i clienti o a entrambi. */
    public static function all(string $defaultAudience = PasswordResetLinks::AUDIENCE_BOTH): Action
    {
        return Action::make('sendPasswordResetAll')->authorize('network.manage')->label('Reset password a tutti')->icon('heroicon-o-key')->color('gray')
            ->modalHeading('Invia il link per scegliere una nuova password a tutti')
            ->modalDescription('Parte una email per ogni account. Le password attuali restano valide finché ciascuno non ne sceglie una nuova. Se l\'invio supera il tempo massimo, ti dice quanti ne restano: rilancialo con «Salta chi ha già ricevuto un link» acceso e riparte da chi manca.')
            ->modalSubmitActionLabel('Invia a tutti')
            ->schema([
                Select::make('audience')->label('Destinatari')->native(false)->required()->default($defaultAudience)->options([
                    PasswordResetLinks::AUDIENCE_CONSULTANTS => 'Tutti gli incaricati',
                    PasswordResetLinks::AUDIENCE_CUSTOMERS => 'Tutti i clienti (FAN)',
                    PasswordResetLinks::AUDIENCE_BOTH => 'Incaricati e clienti',
                ]),
                Toggle::make('include_terminated')->label('Includi gli incaricati cessati')->default(false),
                Toggle::make('skip_recent')->label('Salta chi ha già ricevuto un link nelle ultime '.PasswordResetLinks::RECENT_HOURS.' ore')->default(true),
                TextInput::make('confirm')->label('Scrivi '.self::CONFIRM_WORD.' per confermare')->required()->in([self::CONFIRM_WORD])->dehydrated(false)
                    ->validationMessages(['in' => 'Scrivi '.self::CONFIRM_WORD.' (maiuscolo) per confermare.']),
            ])
            ->action(function (array $data): void {
                $links = app(PasswordResetLinks::class);
                self::run($links->userIdsFor((string) $data['audience'], (bool) ($data['include_terminated'] ?? false)), (bool) ($data['skip_recent'] ?? true));
            });
    }

    /** @param  iterable<int|null>  $userIds */
    private static function run(iterable $userIds, bool $skipRecent): void
    {
        $links = app(PasswordResetLinks::class);
        if (! $links->mailEnabled()) {
            Notification::make()->title('Invio email disattivato')
                ->body('Nessun link è stato creato. Accendi «Invio email attivo» in Impostazioni di sistema e riprova.')->danger()->send();

            return;
        }
        $result = $links->send($userIds, $skipRecent);

        $lines = [];
        foreach ($result['skipped'] as $reason => $n) {
            $lines[] = "Saltati {$n}: ".PasswordResetLinks::SKIP_LABELS[$reason];
        }
        if ($result['failed'] > 0) {
            $lines[] = "Non riusciti: {$result['failed']} (errore del server di posta, controlla il log).";
        }
        if ($result['remaining'] > 0) {
            $lines[] = "Tempo massimo raggiunto: ne restano {$result['remaining']}. Rilancia l'invio con «Salta chi ha già ricevuto un link» acceso.";
        }
        $clean = $result['sent'] > 0 && $result['failed'] === 0 && $result['remaining'] === 0;

        Notification::make()
            ->title($result['sent'] > 0 ? "Link inviati: {$result['sent']}" : 'Nessun link inviato')
            ->body($lines === [] ? null : implode("\n", $lines))
            ->status($clean ? 'success' : 'warning')->persistent($lines !== [])->send();
    }
}
