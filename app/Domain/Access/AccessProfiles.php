<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Profili di permessi per gli admin: quelli preimpostati (Roles::PRESET_LABELS) e quelli personalizzati dal super_admin.
 *
 * Un profilo è solo un MODELLO da cui partire: scegliendolo, i suoi permessi si copiano sull'admin, che poi si
 * può ritoccare. Cambiare o cancellare un profilo non cambia i permessi di chi l'ha già usato.
 * I profili personalizzati sono ruoli spatie con prefisso «profilo:» (nessuna tabella nuova, quindi nessuna migrazione
 * da fare a mano sul sito live). Non vengono mai assegnati a un utente e non danno accesso al back-office.
 * Salvarli e cancellarli è solo del super_admin; ogni operazione finisce nel log di audit.
 */
final class AccessProfiles
{
    public const MIN_LABEL = 3;

    public const MAX_LABEL = 60;

    /**
     * Tutti i profili: chiave => ['label', 'builtin', 'permissions'].
     *
     * @return array<string, array{label: string, builtin: bool, permissions: list<string>}>
     */
    public static function all(): array
    {
        $out = [];
        foreach (Roles::PRESET_LABELS as $key => $label) {
            $out[$key] = ['label' => $label, 'builtin' => true, 'permissions' => PermissionCatalog::ordered(Roles::preset($key))];
        }
        foreach (self::customRoles() as $role) {
            $out[$role->name] = [
                'label' => self::labelOf($role->name),
                'builtin' => false,
                'permissions' => PermissionCatalog::ordered(array_values(array_intersect($role->permissions->pluck('name')->all(), Roles::grantable()))),
            ];
        }

        return $out;
    }

    /** @return array<string, string> chiave => etichetta, per i menu a tendina */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $key => $p) {
            $out[$key] = $p['builtin'] ? $p['label'] : $p['label'].' (personalizzato)';
        }

        return $out;
    }

    /** @return list<string> permessi concedibili di un profilo (vuoto se non esiste) */
    public static function permissions(string $key): array
    {
        return self::all()[$key]['permissions'] ?? [];
    }

    public static function exists(string $key): bool
    {
        return isset(self::all()[$key]);
    }

    public static function isCustom(string $key): bool
    {
        return str_starts_with($key, Roles::PROFILE_PREFIX);
    }

    public static function labelOf(string $key): string
    {
        return self::isCustom($key) ? Str::after($key, Roles::PROFILE_PREFIX) : (Roles::PRESET_LABELS[$key] ?? $key);
    }

    /**
     * Il profilo i cui permessi coincidono esattamente con quelli dati (il primo, preimpostati prima dei personalizzati).
     *
     * @param  list<string>  $permissions
     */
    public static function matching(array $permissions): ?string
    {
        $wanted = PermissionCatalog::ordered(array_values(array_unique($permissions)));
        foreach (self::all() as $key => $profile) {
            if ($profile['permissions'] === $wanted) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Crea un profilo personalizzato o ne aggiorna uno. Restituisce la chiave.
     * I permessi richiesti si aggiungono da soli («gestire» include «vedere»).
     *
     * @param  list<string>  $permissions
     */
    public static function save(string $label, array $permissions, ?string $existingKey = null): string
    {
        self::assertSuperAdmin();
        $label = self::cleanLabel($label);
        if ($existingKey !== null && ! self::isCustom($existingKey)) {
            throw ValidationException::withMessages(['label' => 'I profili preimpostati non si modificano.']);
        }
        $newKey = Roles::PROFILE_PREFIX.$label;
        self::assertLabelFree($label, $newKey, $existingKey);

        $bad = array_diff($permissions, Roles::grantable());
        if ($bad !== []) {
            throw ValidationException::withMessages(['permissions' => 'Permessi non concedibili: '.implode(', ', $bad)]);
        }
        $permissions = PermissionCatalog::close($permissions);

        Roles::sync();
        $role = $existingKey !== null ? Role::findByName($existingKey, 'web') : Role::findOrCreate($newKey, 'web');
        $before = $existingKey !== null ? $role->permissions->pluck('name')->all() : [];
        if ($existingKey !== null && $existingKey !== $newKey) {
            $role->update(['name' => $newKey]);
        }
        $role->syncPermissions($permissions);

        AuditLog::record(
            $existingKey === null ? 'access.profile_created' : 'access.profile_updated',
            ($existingKey === null ? 'Profilo creato: ' : 'Profilo aggiornato: ').$label,
            null,
            ['profilo' => $label, 'permessi' => ['prima' => PermissionCatalog::ordered($before), 'dopo' => $permissions]],
        );

        return $newKey;
    }

    public static function delete(string $key): void
    {
        self::assertSuperAdmin();
        if (! self::isCustom($key)) {
            throw ValidationException::withMessages(['profilo' => 'I profili preimpostati non si cancellano.']);
        }
        $role = Role::query()->where('name', $key)->where('guard_name', 'web')->first();
        if ($role === null) {
            return;
        }
        $permissions = $role->permissions->pluck('name')->all();
        $role->delete();
        AuditLog::record('access.profile_deleted', 'Profilo cancellato: '.self::labelOf($key), null, ['profilo' => self::labelOf($key), 'permessi' => $permissions]);
    }

    /** @return array{label: string, permissions: list<string>} per riempire il modulo di modifica */
    public static function formState(string $key): array
    {
        $profile = self::all()[$key] ?? null;

        return ['label' => $profile['label'] ?? '', 'permissions' => $profile['permissions'] ?? []];
    }

    private static function cleanLabel(string $label): string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $length = mb_strlen($label);
        if ($length < self::MIN_LABEL || $length > self::MAX_LABEL) {
            throw ValidationException::withMessages(['label' => 'Il nome del profilo deve avere da '.self::MIN_LABEL.' a '.self::MAX_LABEL.' caratteri.']);
        }
        if (! preg_match('/^[\p{L}\p{N}][\p{L}\p{N} \'’\-\.\/&]*$/u', $label)) {
            throw ValidationException::withMessages(['label' => 'Il nome può contenere lettere, numeri, spazi e - . \' / &.']);
        }

        return $label;
    }

    private static function assertLabelFree(string $label, string $newKey, ?string $existingKey): void
    {
        foreach (Roles::PRESET_LABELS as $builtin) {
            if (Str::lower($builtin) === Str::lower($label)) {
                throw ValidationException::withMessages(['label' => 'Esiste già un profilo preimpostato con questo nome.']);
            }
        }
        foreach (self::customRoles() as $role) {
            if ($role->name !== $existingKey && Str::lower($role->name) === Str::lower($newKey)) {
                throw ValidationException::withMessages(['label' => 'Esiste già un profilo con questo nome.']);
            }
        }
    }

    private static function assertSuperAdmin(): void
    {
        $user = auth()->user();
        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            throw new AuthorizationException('I profili li gestisce solo il super_admin.');
        }
    }

    /** @return Collection<int, Role> */
    private static function customRoles(): Collection
    {
        return Role::query()->with('permissions')->where('guard_name', 'web')
            ->where('name', 'like', Roles::PROFILE_PREFIX.'%')->orderBy('name')->get();
    }
}
