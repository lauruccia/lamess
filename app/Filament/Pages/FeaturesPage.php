<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Access\Roles;
use App\Domain\Features\Feature;
use App\Domain\Features\FeatureException;
use App\Domain\Features\FeatureRegistry;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Funzionalità e viste: l'admin accende e spegne tab, viste, link e pulsanti senza toccare il codice.
 * Accesso: super_admin e admin con il permesso «features.manage» (concesso dal super_admin, utente per utente).
 * Le regole base valgono per tutti; «Eccezioni» le restringe o le allarga per ruolo o per singolo utente.
 */
class FeaturesPage extends Page implements HasActions
{
    use InteractsWithActions;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Funzionalità e viste';

    protected static ?string $title = 'Funzionalità e viste';

    protected static ?string $slug = 'funzionalita';

    protected static ?int $navigationSort = 92;

    protected string $view = 'filament.pages.features';

    public string $search = '';

    public static function canAccess(): bool
    {
        return Feature::canManage(auth()->user());
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    private function me(): User
    {
        /** @var User $u */
        $u = auth()->user();

        return $u;
    }

    private function isSuper(): bool
    {
        return $this->me()->isSuperAdmin();
    }

    /** Stato base («per tutti») di una funzionalità, senza il genitore: quello che il pulsante imposta. */
    private function baseState(string $key): bool
    {
        foreach (Feature::rulesFor($key) as $r) {
            if ($r['scope_type'] === 'all') {
                return $r['enabled'];
            }
        }

        return FeatureRegistry::get($key)['default'] ?? true;
    }

    public function toggle(string $key): void
    {
        $now = $this->baseState($key);
        try {
            Feature::set($key, ! $now, reason: null);
        } catch (FeatureException $e) {
            Notification::make()->title('Non consentito')->body($e->getMessage())->danger()->send();

            return;
        }
        $n = count(Feature::dependents($key));
        $n > 0 && $now
            ? Notification::make()->title('Funzionalità disattivata')->body("Si disattivano anche gli elementi collegati ({$n}).")->success()->send()
            : Notification::make()->title($now ? 'Funzionalità disattivata' : 'Funzionalità attivata')->success()->send();
    }

    public function toggleLock(string $key): void
    {
        if (! $this->isSuper()) {
            return;
        }
        $locked = Feature::lockedBySuperadmin($key);
        try {
            Feature::set($key, $this->baseState($key), lock: ! $locked);
        } catch (FeatureException $e) {
            Notification::make()->title('Non consentito')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($locked ? 'Regola sbloccata' : 'Regola bloccata: l\'admin non può cambiarla')->success()->send();
    }

    public function restore(string $key): void
    {
        try {
            $n = Feature::resetAll($key);
        } catch (FeatureException $e) {
            Notification::make()->title('Non consentito')->body($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title($n > 0 ? 'Valore predefinito ripristinato' : 'Già al valore predefinito')->success()->send();
    }

    /** Eccezioni per ruolo o per utente. */
    public function exceptionAction(): Action
    {
        return Action::make('exception')->label('Eccezione')->modalHeading('Eccezione per ruolo o per utente')->modalSubmitActionLabel('Salva eccezione')
            ->modalDescription('Per un ruolo o un utente la funzionalità vale come qui sotto, a prescindere dalla regola «per tutti». L\'utente vince sul ruolo.')
            ->schema([
                Select::make('scope')->label('Vale per')->options(['role' => 'Un ruolo', 'user' => 'Un utente'])->required()->live()->native(false),
                Select::make('role')->label('Ruolo')->options(fn () => array_combine(Roles::backoffice(), Roles::backoffice()))->native(false)->visible(fn ($get) => $get('scope') === 'role')->required(fn ($get) => $get('scope') === 'role'),
                Select::make('user')->label('Utente')->searchable()->visible(fn ($get) => $get('scope') === 'user')->required(fn ($get) => $get('scope') === 'user')
                    ->getSearchResultsUsing(fn (string $s) => User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', Roles::backoffice()))->where(fn ($w) => $w->where('email', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%"))->limit(30)->pluck('email', 'id'))
                    ->getOptionLabelUsing(fn ($v) => User::find($v)?->email),
                Toggle::make('enabled')->label('Funzionalità attiva')->default(false),
                TextInput::make('reason')->label('Motivo (facoltativo, finisce nel log)')->maxLength(250),
            ])
            ->action(function (array $data, array $arguments): void {
                $scope = (string) $data['scope'];
                try {
                    Feature::set((string) $arguments['key'], (bool) $data['enabled'], $scope, $scope === 'role' ? (string) $data['role'] : (string) $data['user'], $data['reason'] ?: null);
                    Notification::make()->title('Eccezione salvata')->success()->send();
                } catch (FeatureException $e) {
                    Notification::make()->title('Non consentito')->body($e->getMessage())->danger()->send();
                }
            });
    }

    public function removeException(string $key, string $scopeType, string $scopeId): void
    {
        try {
            Feature::reset($key, $scopeType, $scopeId);
            Notification::make()->title('Eccezione tolta')->success()->send();
        } catch (FeatureException $e) {
            Notification::make()->title('Non consentito')->body($e->getMessage())->danger()->send();
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $needle = mb_strtolower(trim($this->search));
        $groups = [];
        foreach (FeatureRegistry::all() as $key => $def) {
            if (($def['super'] || $this->underMenuEntry($key)) && ! $this->isSuper()) {
                continue;   // le voci di menu le gestisce solo il super_admin: l'admin non sa nemmeno che esistono
            }
            if ($needle !== '' && ! str_contains(mb_strtolower($key.' '.$def['label'].' '.($def['help'] ?? '')), $needle)) {
                continue;
            }
            $rules = Feature::rulesFor($key);
            $groups[$def['group']][] = [
                'key' => $key, 'def' => $def, 'on' => $this->baseState($key), 'effective' => Feature::enabled($key, $this->me()),
                'system' => ($def['lock'] ?? null) === 'system', 'locked' => Feature::lockedBySuperadmin($key),
                'exceptions' => array_values(array_filter($rules, static fn ($r) => $r['scope_type'] !== 'all')),
                'customised' => $rules !== [],
                'children' => count(Feature::dependents($key)),
                'depth' => $this->depth($key),
                'type' => FeatureRegistry::typeLabel($def['type']),
            ];
        }

        return ['groups' => $groups, 'isSuper' => $this->isSuper(), 'scopeLabel' => Feature::scopeLabel(...)];
    }

    /** True per le voci di menu e per tutto ciò che ne dipende (es. le colonne dell'elenco incaricati). */
    private function underMenuEntry(string $key): bool
    {
        while ($key !== null) {
            $def = FeatureRegistry::get($key);
            if ($def === null) {
                return false;
            }
            if (isset($def['menu'])) {
                return true;
            }
            $key = $def['parent'] ?? null;
        }

        return false;
    }

    private function depth(string $key): int
    {
        $d = 0;
        while (($p = FeatureRegistry::get($key)['parent'] ?? null) !== null) {
            $d++;
            $key = $p;
        }

        return $d;
    }
}
