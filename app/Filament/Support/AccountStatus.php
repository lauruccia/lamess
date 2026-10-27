<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\Access\AccountSuspension;
use App\Models\Consultant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Sospendi / banna / riattiva un account dagli elenchi Utenti, Incaricati e Clienti, e filtro «Account» che di
 * default mostra solo gli account attivi: sospesi e bannati spariscono dall'elenco e si ritrovano scegliendo il filtro.
 * Il record dell'elenco può essere un utente, un incaricato o un cliente.
 */
final class AccountStatus
{
    /** Id dell'utente collegato al record (nessuna query: si usa la colonna). */
    private static function userId(Model $record): ?int
    {
        $id = $record instanceof User ? $record->getKey() : $record->getAttribute('user_id');

        return $id === null ? null : (int) $id;
    }

    /** Tipo di blocco del record (suspended | banned) o null se l'account è attivo. */
    public static function typeOf(Model $record): ?string
    {
        if ($record instanceof User) {
            return $record->isSuspended() ? (string) $record->suspension_type : null;
        }
        if ($record instanceof Consultant && array_key_exists('user_suspension_type', $record->getAttributes())) {
            return $record->getAttribute('user_suspension_type');
        }
        $user = $record->user ?? null;

        return $user instanceof User && $user->isSuspended() ? (string) $user->suspension_type : null;
    }

    private static function canUse(Model $record): bool
    {
        $actor = auth()->user();

        return self::userId($record) !== null && $actor instanceof User && ($actor->isSuperAdmin() || $actor->can('network.manage'));
    }

    private static function target(Model $record): ?User
    {
        $id = self::userId($record);

        return $id === null ? null : User::query()->find($id);
    }

    public static function suspendAction(): Action
    {
        return Action::make('suspendAccount')->label('Sospendi o banna')->icon('heroicon-o-no-symbol')->color('danger')
            ->visible(fn (Model $record): bool => self::canUse($record) && self::typeOf($record) === null)
            ->modalHeading('Sospendi o banna l\'account')
            ->modalDescription('La persona non può più entrare, le sue sessioni si chiudono, i suoi abbonamenti non vengono più addebitati e sparisce da elenchi, alberi e link referral. Ordini, fatture e compensi restano. Si può riattivare in qualsiasi momento dal filtro «Account».')
            ->modalSubmitActionLabel('Conferma')->requiresConfirmation()
            ->schema([
                Radio::make('type')->label('Cosa vuoi fare')->options(['suspended' => 'Sospendi (stop temporaneo)', 'banned' => 'Banna (blocco deciso)'])->default('suspended')->required(),
                Textarea::make('reason')->label('Motivo (resta nel log di audit)')->required()->maxLength(500)->rows(3),
            ])
            ->action(function (Model $record, array $data): void {
                $user = self::target($record);
                try {
                    abort_if($user === null, 404);
                    app(AccountSuspension::class)->suspend($user, (string) $data['type'], (string) $data['reason'], auth()->user());
                    Notification::make()->title($data['type'] === 'banned' ? 'Account bannato' : 'Account sospeso')->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title('Operazione non consentita')->body(collect($e->errors())->flatten()->first())->danger()->send();
                }
            });
    }

    public static function reinstateAction(): Action
    {
        return Action::make('reinstateAccount')->label('Riattiva account')->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (Model $record): bool => self::canUse($record) && self::typeOf($record) !== null)
            ->requiresConfirmation()->modalHeading('Riattivare l\'account?')
            ->modalDescription('La persona potrà di nuovo entrare e tornerà visibile in elenchi, alberi e link referral.')
            ->modalSubmitActionLabel('Riattiva')
            ->action(function (Model $record): void {
                $user = self::target($record);
                try {
                    abort_if($user === null, 404);
                    app(AccountSuspension::class)->reinstate($user, auth()->user());
                    Notification::make()->title('Account riattivato')->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title('Operazione non consentita')->body(collect($e->errors())->flatten()->first())->danger()->send();
                }
            });
    }

    /** Colonna con il badge «Sospeso» / «Bannato» (vuota per gli account attivi). */
    public static function column(): TextColumn
    {
        return TextColumn::make('account_status')->label('Account')->badge()->placeholder('—')
            ->state(fn (Model $record): ?string => ($t = self::typeOf($record)) === null ? null : (AccountSuspension::LABELS[$t] ?? $t))
            ->color(fn (?string $state) => $state === 'Bannato' ? 'danger' : 'warning');
    }

    /**
     * Filtro «Account»: di default solo gli attivi.
     *
     * @param  string  $userColumn  colonna con l'id utente (per gli utenti stessi: users.id)
     */
    public static function filter(string $userColumn): SelectFilter
    {
        return SelectFilter::make('account_status')->label('Account')->native(false)->selectablePlaceholder(false)
            ->options(['visible' => 'Attivi', 'suspended' => 'Sospesi', 'banned' => 'Bannati', 'blocked' => 'Sospesi e bannati'])
            ->default('visible')
            ->query(function (Builder $query, array $data) use ($userColumn): Builder {
                $mode = $data['value'] ?? 'visible';
                $blocked = AccountSuspension::suspendedUserIds();

                return match ($mode) {
                    'suspended' => $query->whereIn($userColumn, $blocked->clone()->where('suspension_type', 'suspended')),
                    'banned' => $query->whereIn($userColumn, $blocked->clone()->where('suspension_type', 'banned')),
                    'blocked' => $query->whereIn($userColumn, $blocked),
                    default => $query->where(fn ($w) => $w->whereNull($userColumn)->orWhereNotIn($userColumn, $blocked)),
                };
            });
    }
}
