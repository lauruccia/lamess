<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Domain\Access\Roles;
use App\Domain\Access\UserAdministration;
use App\Domain\Features\FeatureRegistry;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Concerns\ImpersonateAction;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Support\PasswordResetAction;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Utenti e permessi — SOLO super_admin (si controlla il ruolo, non un permesso: nessun admin può riceverlo).
 * Non si cancella nessuno da qui; il ruolo super_admin non si assegna e non si toglie.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $modelLabel = 'utente';

    protected static ?string $pluralModelLabel = 'Utenti';

    protected static ?int $navigationSort = 90;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    /** Si eliminano tutti gli utenti tranne i super_admin e se stessi (per non restare fuori dal portale). */
    public static function canDelete($record): bool
    {
        return auth()->user()?->isSuperAdmin() === true && ! $record->isSuperAdmin() && auth()->id() !== $record->getKey();
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->isSuperAdmin() === true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Dati')->columns(2)->schema([
                TextInput::make('name')->label('Nome e cognome')->required()->maxLength(190),
                TextInput::make('email')->label('Email')->email()->required()->maxLength(190)
                    ->unique('users', 'email', ignoreRecord: true),
            ]),
            Section::make('Permessi')
                ->description('Valgono solo per gli admin. Utenti, impostazioni di sistema e log di audit restano sempre riservati al super_admin.')
                ->visible(fn (?User $record) => $record === null || $record->hasRole(Roles::ADMIN))
                ->schema([
                    Select::make('preset')->label('Parti da un profilo')->dehydrated(false)->native(false)
                        ->options(Roles::PRESET_LABELS)->placeholder('Scegli un profilo per pre-compilare i permessi')
                        ->live()->afterStateUpdated(fn ($state, Set $set) => $set('permissions', $state ? Roles::preset((string) $state) : [])),
                    CheckboxList::make('permissions')->label('Permessi concessi')->columns(2)->bulkToggleable()
                        ->options(collect(Roles::grantable())->mapWithKeys(fn (string $p) => [$p => LegacyPlan::scrub(Roles::PERMISSIONS[$p])])->all()),
                ]),
            Section::make('Voci di menu visibili')
                ->description('Il permesso decide cosa può fare; qui scegli cosa vede. Le voci non spuntate sono nascoste dal menu e bloccate anche se l\'utente conosce l\'indirizzo. Le caselle del Cruscotto riguardano i grafici della pagina iniziale.')
                ->visible(fn (?User $record) => $record === null || $record->hasRole(Roles::ADMIN))
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema(self::menuFields()),
            Section::make()->visible(fn (?User $record) => $record === null)->schema([
                Text::make('La password temporanea viene generata dal sistema e mostrata una sola volta alla creazione. Al primo accesso l\'admin deve collegare l\'app di autenticazione (2FA).'),
            ]),
        ]);
    }

    /** @return list<CheckboxList> una lista di caselle per ogni gruppo del menu */
    private static function menuFields(): array
    {
        $fields = [];
        foreach (FeatureRegistry::menuEntries() as $group => $entries) {
            $fields[] = CheckboxList::make('menu.'.UserAdministration::menuGroupKey($group))->label($group)->options($entries)->bulkToggleable();
        }

        return $fields;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('name')->label('Nome')->searchable()->sortable(),
            TextColumn::make('email')->label('Email')->searchable()->sortable(),
            TextColumn::make('roles.name')->label('Ruolo')->badge()
                ->color(fn (string $state) => match ($state) {
                    Roles::SUPER_ADMIN => 'danger', Roles::ADMIN => 'warning', 'consultant' => 'success', default => 'gray',
                }),
            IconColumn::make('two_factor')->label('2FA')->boolean()->state(fn (User $r) => filled($r->app_authentication_secret)),
            TextColumn::make('created_at')->label('Creato il')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
        ])->filters([
            SelectFilter::make('role')->label('Ruolo')->options([
                Roles::SUPER_ADMIN => 'Super admin', Roles::ADMIN => 'Admin', 'consultant' => 'Incaricato', 'customer' => 'Cliente',
            ])->query(fn (Builder $query, array $data) => filled($data['value'] ?? null) ? $query->whereHas('roles', fn ($r) => $r->where('name', $data['value'])) : $query),
        ])->toolbarActions([
            PasswordResetAction::bulk(),
        ])->recordActions([
            EditAction::make()->iconButton()->tooltip('Modifica'),
            ImpersonateAction::forRow(fn (User $r) => $r, true),
            Action::make('resetPassword')->label('Nuova password')->iconButton()->tooltip('Nuova password')->icon('heroicon-o-key')->color('gray')
                ->visible(fn (User $r) => ! $r->isSuperAdmin())
                ->requiresConfirmation()->modalDescription('Genera una password temporanea (mostrata una volta sola) e chiude le sessioni aperte.')
                ->action(function (User $record): void {
                    $password = app(UserAdministration::class)->resetPassword($record);
                    Notification::make()->title('Password temporanea')->body($password)->persistent()->success()->send();
                }),
            PasswordResetAction::forRow()->iconButton()->tooltip('Invia link di reset'),
            Action::make('resetTwoFactor')->label('Azzera 2FA')->iconButton()->tooltip('Azzera 2FA')->icon('heroicon-o-device-phone-mobile')->color('gray')
                ->visible(fn (User $r) => ! $r->isSuperAdmin() && filled($r->app_authentication_secret))
                ->requiresConfirmation()->modalDescription('Da usare se l\'utente ha perso il telefono: al prossimo accesso dovrà collegare di nuovo l\'app.')
                ->action(function (User $record): void {
                    app(UserAdministration::class)->resetTwoFactor($record);
                    Notification::make()->title('2FA azzerata')->success()->send();
                }),
            Action::make('revoke')->label('Revoca accesso')->iconButton()->tooltip('Revoca accesso')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (User $r) => $r->hasRole(Roles::ADMIN))
                ->requiresConfirmation()->modalDescription('Toglie ruolo e permessi di back-office. L\'utente non viene cancellato.')
                ->action(function (User $record): void {
                    try {
                        app(UserAdministration::class)->revokeBackoffice($record);
                        Notification::make()->title('Accesso revocato')->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title('Operazione non consentita')->body($e->getMessage())->danger()->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
