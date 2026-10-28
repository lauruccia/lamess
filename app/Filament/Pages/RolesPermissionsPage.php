<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\AccessProfiles;
use App\Domain\Access\AccessReport;
use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\Roles;
use App\Domain\Access\Superadmin;
use App\Domain\Audit\AuditLog;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\PermissionFields;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Ruoli e permessi — SOLO super_admin. Tre viste:
 *  - Matrice: chi può fare cosa (permessi × profili), con la spiegazione di ogni permesso;
 *  - Profili: i modelli di permessi preimpostati e quelli personalizzati (creare, copiare, modificare, cancellare);
 *  - Utenti: l'accesso effettivo di ogni utente del back-office (permessi, menu visibile, avvisi).
 * Non cambia i permessi di nessun utente (si fa in Utenti): un profilo è solo un punto di partenza.
 */
class RolesPermissionsPage extends Page implements HasActions
{
    use InteractsWithActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Ruoli e permessi';

    protected static ?string $title = 'Ruoli e permessi';

    protected static ?string $slug = 'ruoli-permessi';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.pages.roles-permissions';

    /** matrice | profili | utenti */
    public string $tab = 'matrice';

    public static function canAccess(): bool
    {
        return Superadmin::is();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['matrice', 'profili', 'utenti'], true) ? $tab : 'matrice';
    }

    /** Crea un profilo, ne modifica uno personalizzato (`key`) o parte da una copia (`from`). */
    public function profileAction(): Action
    {
        return Action::make('profile')->modalWidth('4xl')
            ->modalHeading(fn (array $arguments): string => isset($arguments['key']) ? 'Modifica profilo' : 'Nuovo profilo')
            ->modalDescription('Un profilo è un modello di permessi: serve per partire in fretta quando si crea o si modifica un admin. Cambiarlo non cambia i permessi di chi l\'ha già usato.')
            ->modalSubmitActionLabel('Salva profilo')
            ->schema([
                TextInput::make('label')->label('Nome del profilo')->required()->minLength(AccessProfiles::MIN_LABEL)->maxLength(AccessProfiles::MAX_LABEL),
                ...PermissionFields::lists(),
            ])
            ->fillForm(function (array $arguments): array {
                if (isset($arguments['key'])) {
                    $state = AccessProfiles::formState((string) $arguments['key']);

                    return ['label' => $state['label'], 'perm' => PermissionCatalog::splitByArea($state['permissions'])];
                }
                if (isset($arguments['from'])) {
                    $state = AccessProfiles::formState((string) $arguments['from']);

                    return ['label' => 'Copia di '.$state['label'], 'perm' => PermissionCatalog::splitByArea($state['permissions'])];
                }

                return ['label' => '', 'perm' => PermissionCatalog::splitByArea([])];
            })
            ->action(function (array $data, array $arguments, Action $action): void {
                try {
                    AccessProfiles::save((string) $data['label'], PermissionCatalog::flatten($data['perm'] ?? null), isset($arguments['key']) ? (string) $arguments['key'] : null);
                } catch (ValidationException $e) {
                    Notification::make()->title('Profilo non salvato')->body(collect($e->errors())->flatten()->first())->danger()->send();
                    $action->halt();

                    return;
                }
                Notification::make()->title('Profilo salvato')->success()->send();
                $this->tab = 'profili';
            });
    }

    public function deleteProfileAction(): Action
    {
        return Action::make('deleteProfile')->requiresConfirmation()
            ->modalHeading('Cancellare il profilo?')
            ->modalDescription('Il profilo sparisce dall\'elenco. I permessi di chi l\'ha già usato non cambiano.')
            ->modalSubmitActionLabel('Cancella profilo')->color('danger')
            ->action(function (array $arguments): void {
                try {
                    AccessProfiles::delete((string) $arguments['key']);
                } catch (ValidationException $e) {
                    Notification::make()->title('Non consentito')->body(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }
                Notification::make()->title('Profilo cancellato')->success()->send();
            });
    }

    /** Scheda dell'accesso effettivo di un utente del back-office. */
    public function userAccessAction(): Action
    {
        return Action::make('userAccess')->modalWidth('4xl')->modalSubmitAction(false)->modalCancelActionLabel('Chiudi')
            ->modalHeading(fn (array $arguments): string => 'Accesso di '.(User::find((int) ($arguments['id'] ?? 0))?->name ?? 'utente'))
            ->modalContent(function (array $arguments) {
                $user = User::query()->whereKey((int) ($arguments['id'] ?? 0))->first();
                abort_unless($user !== null && $user->isBackoffice() && ! $user->isSuperAdmin(), 404);

                return view('filament.pages.partials.user-access', ['r' => AccessReport::forUser($user), 'editUrl' => UserResource::getUrl('edit', ['record' => $user])]);
            });
    }

    /** Crea nel database i permessi che il codice dichiara e il sito live ancora non ha (niente terminale: serve questo). */
    public function syncAction(): Action
    {
        return Action::make('sync')->label('Sincronizza permessi')->icon('heroicon-o-arrow-path')->color('gray')
            ->requiresConfirmation()->modalHeading('Sincronizzare ruoli e permessi?')
            ->modalDescription('Crea i permessi nuovi che mancano nel database e aggiorna i profili preimpostati. Non toglie né aggiunge permessi agli utenti.')
            ->action(function (): void {
                Roles::sync();
                AuditLog::record('access.roles_synced', 'Ruoli e permessi sincronizzati dal pannello');
                Notification::make()->title('Permessi sincronizzati')->success()->send();
            });
    }

    public function exportMatrixAction(): Action
    {
        return Action::make('exportMatrix')->label('Esporta matrice (CSV)')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->action(fn (): StreamedResponse => $this->download(AccessReport::matrixCsv(), 'matrice-permessi.csv', 'access.matrix_exported'));
    }

    public function exportUsersAction(): Action
    {
        return Action::make('exportUsers')->label('Esporta utenti (CSV)')->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->action(fn (): StreamedResponse => $this->download(AccessReport::usersCsv(), 'accessi-utenti.csv', 'access.users_exported'));
    }

    protected function getHeaderActions(): array
    {
        return [$this->exportMatrixAction(), $this->exportUsersAction(), $this->syncAction()];
    }

    private function download(string $csv, string $name, string $auditAction): StreamedResponse
    {
        AuditLog::record($auditAction, 'Esportato '.$name);

        return response()->streamDownload(static function () use ($csv): void {
            echo $csv;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $users = $this->tab === 'utenti'
            ? AccessReport::backofficeUsers()->map(fn (User $u): array => AccessReport::forUser($u))->all()
            : [];

        return [
            'tab' => $this->tab,
            'matrix' => $this->tab === 'matrice' ? AccessReport::matrix() : null,
            'profiles' => $this->tab === 'profili' ? AccessProfiles::all() : [],
            'users' => $users,
            'areas' => PermissionCatalog::areas(),
            'editUrl' => static fn (User $u): string => UserResource::getUrl('edit', ['record' => $u]),
        ];
    }
}
