<?php

declare(strict_types=1);

namespace App\Domain\Features;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interruttori on/off per tab, viste, link e pulsanti (elenco in FeatureRegistry).
 *
 * Ordine di decisione per una funzionalità e un utente:
 *  1. chiave sconosciuta → abilitata (il codice non si blocca mai per una chiave dimenticata)
 *  2. super_admin → abilitata, salvo `bypass=false` (il titolare non si chiude fuori)
 *  3. `lock=system` → abilitata, non si spegne
 *  4. genitore spento → spenta
 *  5. regola per UTENTE → vince su tutto il resto
 *  6. regola per RUOLO → se ce n'è una accesa per uno dei ruoli dell'utente, accesa; altrimenti spenta
 *  7. regola per TUTTI
 *  8. default della funzionalità
 *
 * Spegnere non cancella dati e non tocca i calcoli: cambia solo ciò che si vede e si può usare. Il controllo vero
 * (route, azioni, esportazioni) deve chiamare enabled() lato server, non solo nascondere un elemento.
 */
final class Feature
{
    private const CACHE_KEY = 'lamess.features.v1';

    /** Copie per richiesta tenute nel contenitore (si azzerano da sole a ogni richiesta e a ogni test). */
    private const MEMO = 'lamess.features.memo';

    private const ROLES_MEMO = 'lamess.features.roles';

    public static function enabled(string $key, ?Authenticatable $user = null): bool
    {
        $def = FeatureRegistry::get($key);
        if ($def === null) {
            return true;
        }
        $user ??= Auth::user();
        $user = $user instanceof User ? $user : null;

        if ($user?->isSuperAdmin() === true && $def['bypass']) {
            return true;
        }
        if (($def['lock'] ?? null) === 'system') {
            return $def['super'] ? $user?->isSuperAdmin() === true : true;
        }
        if (isset($def['parent']) && ! self::enabled($def['parent'], $user)) {
            return false;
        }
        if ($def['super'] && $user?->isSuperAdmin() !== true) {
            // riservata al super_admin: all'admin non si apre nemmeno se qualcuno la accende
            return false;
        }

        $rows = self::rows()[$key] ?? [];
        if ($user !== null) {
            foreach ($rows as $r) {
                if ($r['scope_type'] === 'user' && $r['scope_id'] === (string) $user->id) {
                    return $r['enabled'];
                }
            }
            $roleRows = array_values(array_filter($rows, static fn ($r) => $r['scope_type'] === 'role' && in_array($r['scope_id'], self::rolesOf($user), true)));
            if ($roleRows !== []) {
                return (bool) array_filter($roleRows, static fn ($r) => $r['enabled']);
            }
        }
        foreach ($rows as $r) {
            if ($r['scope_type'] === 'all') {
                return $r['enabled'];
            }
        }

        return $def['default'];
    }

    /** True se la regola è bloccata dal super_admin (l'admin non può cambiarla). */
    public static function lockedBySuperadmin(string $key): bool
    {
        foreach (self::rows()[$key] ?? [] as $r) {
            if ($r['locked']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Imposta una regola. Solo chi ha il permesso `features.manage` (o il super_admin); le chiavi di sistema non si
     * toccano, le regole bloccate dal super_admin le cambia solo lui. Ogni cambio va nel log di audit.
     *
     * @param  'all'|'role'|'user'  $scopeType
     */
    public static function set(string $key, bool $enabled, string $scopeType = 'all', string $scopeId = '', ?string $reason = null, ?bool $lock = null, ?User $by = null): void
    {
        $by ??= Auth::user() instanceof User ? Auth::user() : null;
        $def = self::guard($key, $scopeType, $scopeId, $by);
        if (($def['lock'] ?? null) === 'system' && ! $enabled) {
            throw new FeatureException('Questa funzionalità è protetta e non si può disattivare.');
        }
        if ($lock !== null && $by?->isSuperAdmin() !== true) {
            throw new FeatureException('Solo il super_admin può bloccare una regola.');
        }
        $previous = self::stored($key, $scopeType, $scopeId);

        DB::transaction(function () use ($key, $enabled, $scopeType, $scopeId, $reason, $lock, $by, $previous): void {
            DB::table('ui_feature_settings')->updateOrInsert(
                ['feature_key' => $key, 'scope_type' => $scopeType, 'scope_id' => $scopeId],
                [
                    'enabled' => $enabled,
                    'locked_by_superadmin' => $lock ?? (bool) ($previous['locked'] ?? false),
                    'reason' => $reason,
                    'updated_by' => Actor::label($by),
                    'updated_at' => now(),
                    'created_at' => $previous === null ? now() : DB::raw('created_at'),
                ],
            );
        });
        self::flush();
        AuditLog::record('features.set', ($enabled ? 'Attivata' : 'Disattivata').' la funzionalità «'.$key.'» ('.self::scopeLabel($scopeType, $scopeId).')', ['Feature', $key],
            ['enabled' => ['prima' => $previous['enabled'] ?? null, 'dopo' => $enabled], 'ambito' => self::scopeLabel($scopeType, $scopeId)] + ($lock !== null ? ['bloccata' => $lock] : []), $reason);
    }

    /** Toglie una regola: la funzionalità torna a seguire il default (o la regola più generale). */
    public static function reset(string $key, string $scopeType = 'all', string $scopeId = '', ?string $reason = null, ?User $by = null): void
    {
        $by ??= Auth::user() instanceof User ? Auth::user() : null;
        self::guard($key, $scopeType, $scopeId, $by);
        $previous = self::stored($key, $scopeType, $scopeId);
        if ($previous === null) {
            return;
        }
        DB::table('ui_feature_settings')->where(['feature_key' => $key, 'scope_type' => $scopeType, 'scope_id' => $scopeId])->delete();
        self::flush();
        AuditLog::record('features.reset', 'Ripristinato il valore predefinito di «'.$key.'» ('.self::scopeLabel($scopeType, $scopeId).')', ['Feature', $key],
            ['enabled' => ['prima' => $previous['enabled'], 'dopo' => 'predefinito'], 'ambito' => self::scopeLabel($scopeType, $scopeId)], $reason);
    }

    /** Toglie tutte le regole (di ogni ambito) di una funzionalità. */
    public static function resetAll(string $key, ?string $reason = null, ?User $by = null): int
    {
        $by ??= Auth::user() instanceof User ? Auth::user() : null;
        self::guard($key, 'all', '', $by);
        $n = DB::table('ui_feature_settings')->where('feature_key', $key)->delete();
        self::flush();
        if ($n > 0) {
            AuditLog::record('features.reset', 'Ripristinato il valore predefinito di «'.$key.'» (tutti gli ambiti)', ['Feature', $key], ['regole_tolte' => $n], $reason);
        }

        return $n;
    }

    /** Chi può cambiare le regole. */
    public static function canManage(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User && ($user->isSuperAdmin() || $user->can('features.manage'));
    }

    /** @return list<array{scope_type: string, scope_id: string, enabled: bool, locked: bool}> le regole salvate per una chiave */
    public static function rulesFor(string $key): array
    {
        return self::rows()[$key] ?? [];
    }

    /** @return list<string> chiavi figlie che si spengono insieme (per l'avviso del pannello) */
    public static function dependents(string $key): array
    {
        return FeatureRegistry::descendants($key);
    }

    public static function scopeLabel(string $type, string $id): string
    {
        return match ($type) {
            'role' => 'ruolo '.$id,
            'user' => 'utente '.($id !== '' ? (User::find((int) $id)?->email ?? "#{$id}") : ''),
            default => 'per tutti',
        };
    }

    public static function flush(): void
    {
        app()->forgetInstance(self::MEMO);
        app()->forgetInstance(self::ROLES_MEMO);
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{lock?: string, super: bool, ...} */
    private static function guard(string $key, string $scopeType, string $scopeId, ?User $by): array
    {
        $def = FeatureRegistry::get($key) ?? throw new FeatureException("Funzionalità sconosciuta: {$key}.");
        if (! in_array($scopeType, ['all', 'role', 'user'], true) || ($scopeType === 'all') !== ($scopeId === '')) {
            throw new FeatureException('Ambito non valido.');
        }
        if (! self::canManage($by)) {
            throw new FeatureException('Non hai il permesso di cambiare le funzionalità.');
        }
        if ($def['super'] && $by?->isSuperAdmin() !== true) {
            throw new FeatureException('Questa funzionalità la gestisce solo il super_admin.');
        }
        if (isset($def['menu']) && $by?->isSuperAdmin() !== true) {
            // voci di menu e blocchi del cruscotto: li decide solo il super_admin, l'admin non li vede né li tocca
            throw new FeatureException('Questa funzionalità la gestisce solo il super_admin.');
        }
        if (self::lockedBySuperadmin($key) && $by?->isSuperAdmin() !== true) {
            throw new FeatureException('Questa regola è bloccata dal super_admin.');
        }

        return $def;
    }

    /** @return array{enabled: bool, locked: bool}|null */
    private static function stored(string $key, string $scopeType, string $scopeId): ?array
    {
        foreach (self::rows()[$key] ?? [] as $r) {
            if ($r['scope_type'] === $scopeType && $r['scope_id'] === $scopeId) {
                return ['enabled' => $r['enabled'], 'locked' => $r['locked']];
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function rolesOf(User $user): array
    {
        $memo = app()->bound(self::ROLES_MEMO) ? app(self::ROLES_MEMO) : [];
        if (! isset($memo[$user->id])) {
            $memo[$user->id] = $user->getRoleNames()->all();
            app()->instance(self::ROLES_MEMO, $memo);
        }

        return $memo[$user->id];
    }

    /** @return array<string, list<array{scope_type: string, scope_id: string, enabled: bool, locked: bool}>> */
    private static function rows(): array
    {
        if (app()->bound(self::MEMO)) {
            return app(self::MEMO);
        }
        if (! Schema::hasTable('ui_feature_settings')) {
            app()->instance(self::MEMO, []);

            return [];
        }
        $out = Cache::rememberForever(self::CACHE_KEY, static function (): array {
            $o = [];
            foreach (DB::table('ui_feature_settings')->get() as $r) {
                $o[$r->feature_key][] = ['scope_type' => (string) $r->scope_type, 'scope_id' => (string) $r->scope_id, 'enabled' => (bool) $r->enabled, 'locked' => (bool) $r->locked_by_superadmin];
            }

            return $o;
        });

        app()->instance(self::MEMO, $out);

        return $out;
    }
}
