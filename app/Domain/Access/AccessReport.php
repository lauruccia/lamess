<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Features\FeatureRegistry;
use App\Domain\Plan\LegacyPlan;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Quadro di sola lettura di chi può fare cosa: matrice permessi × profili e accesso effettivo di ogni utente del
 * back-office. Non scrive niente: serve alla pagina «Ruoli e permessi» (super_admin) e alle sue esportazioni.
 */
final class AccessReport
{
    /**
     * Righe della matrice raggruppate per area. Le colonne sono i profili (preimpostati e personalizzati).
     *
     * @return array{profiles: list<array{key: string, label: string, builtin: bool}>, areas: list<array{key: string, label: string, help: string, rows: list<array{key: string, label: string, detail: string, sensitive: bool, requires: list<string>, system_only: bool, in: array<string, bool>}>}>}
     */
    public static function matrix(): array
    {
        $profiles = AccessProfiles::all();
        $columns = [];
        foreach ($profiles as $key => $p) {
            $columns[] = ['key' => $key, 'label' => $p['label'], 'builtin' => $p['builtin']];
        }

        $areas = [];
        foreach (PermissionCatalog::AREAS as $areaKey => $meta) {
            $rows = [];
            foreach (PermissionCatalog::PERMISSIONS as $perm => $def) {
                if ($def['area'] !== $areaKey) {
                    continue;
                }
                $systemOnly = in_array($perm, Roles::SYSTEM_ONLY, true);
                $in = [];
                foreach ($profiles as $key => $p) {
                    $in[$key] = ! $systemOnly && in_array($perm, $p['permissions'], true);
                }
                $rows[] = [
                    'key' => $perm,
                    'label' => LegacyPlan::scrub(Roles::PERMISSIONS[$perm] ?? $perm),
                    'detail' => LegacyPlan::scrub($def['detail']),
                    'sensitive' => (bool) ($def['sensitive'] ?? false),
                    'requires' => $def['requires'] ?? [],
                    'system_only' => $systemOnly,
                    'in' => $in,
                ];
            }
            if ($rows !== []) {
                $areas[] = ['key' => $areaKey, 'label' => $meta['label'], 'help' => $meta['help'], 'rows' => $rows];
            }
        }

        return ['profiles' => $columns, 'areas' => $areas];
    }

    /** @return Collection<int, User> gli utenti del back-office che non sono super_admin */
    public static function backofficeUsers(): Collection
    {
        return User::query()
            ->whereHas('roles', static fn ($q) => $q->whereIn('name', array_values(array_diff(Roles::backoffice(), [Roles::SUPER_ADMIN]))))
            ->with(['roles', 'permissions'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Accesso effettivo di un utente del back-office: permessi per area, voci di menu visibili e nascoste, avvisi.
     *
     * @return array{
     *     user: User, roles: list<string>, super: bool, suspended: bool, two_factor: bool, profile: ?string,
     *     permission_count: int, permissions: list<array{key: string, label: string, area: string, area_label: string, detail: string, sensitive: bool, source: string}>,
     *     sensitive: list<string>, menu_visible: array<string, list<string>>, menu_hidden: array<string, list<string>>,
     *     menu_visible_count: int, menu_total: int, warnings: list<string>
     * }
     */
    public static function forUser(User $user): array
    {
        $super = $user->isSuperAdmin();
        $roleNames = $user->getRoleNames()->values()->all();
        $direct = $user->getDirectPermissions()->pluck('name')->all();
        $viaRole = $user->getPermissionsViaRoles()->pluck('name')->all();
        $granted = $super ? Roles::grantable() : PermissionCatalog::ordered(array_values(array_unique([...$direct, ...$viaRole])));

        $permissions = [];
        foreach ($granted as $perm) {
            $def = PermissionCatalog::PERMISSIONS[$perm] ?? null;
            if ($def === null) {
                continue;
            }
            $permissions[] = [
                'key' => $perm,
                'label' => LegacyPlan::scrub(Roles::PERMISSIONS[$perm] ?? $perm),
                'area' => $def['area'],
                'area_label' => PermissionCatalog::AREAS[$def['area']]['label'],
                'detail' => LegacyPlan::scrub($def['detail']),
                'sensitive' => (bool) ($def['sensitive'] ?? false),
                'source' => $super ? 'ruolo' : (in_array($perm, $direct, true) ? 'singolo' : 'ruolo'),
            ];
        }

        $visibleKeys = $super ? FeatureRegistry::menuKeys() : app(UserAdministration::class)->visibleKeys($user);
        $visible = [];
        $hidden = [];
        foreach (FeatureRegistry::menuEntries() as $group => $entries) {
            foreach ($entries as $key => $label) {
                $label = LegacyPlan::scrub($label);
                in_array($key, $visibleKeys, true) ? $visible[$group][] = $label : $hidden[$group][] = $label;
            }
        }

        $twoFactor = filled($user->app_authentication_secret);
        $warnings = [];
        if (! $super) {
            if ($permissions === []) {
                $warnings[] = 'Non ha nessun permesso: entra nel pannello ma non può aprire niente.';
            }
            if (! $twoFactor) {
                $warnings[] = 'Non ha ancora collegato l\'app di autenticazione (2FA).';
            }
            $missing = [];
            foreach ($granted as $perm) {
                foreach (PermissionCatalog::requiresOf($perm) as $needed) {
                    if (! in_array($needed, $granted, true)) {
                        $missing[$needed] = true;
                    }
                }
            }
            if ($missing !== []) {
                $warnings[] = 'Mancano permessi che quelli concessi richiedono: '.implode(', ', array_keys($missing)).'. Si completano salvando di nuovo i permessi.';
            }
            if ($permissions !== [] && $visibleKeys === []) {
                $warnings[] = 'Ha dei permessi ma tutte le voci di menu sono nascoste: non vede niente.';
            }
            if ($user->isSuspended()) {
                $warnings[] = 'L\'account è sospeso o bannato: non può entrare.';
            }
        }

        return [
            'user' => $user,
            'roles' => $roleNames,
            'super' => $super,
            'suspended' => $user->isSuspended(),
            'two_factor' => $twoFactor,
            'profile' => $super ? null : AccessProfiles::matching($granted),
            'permission_count' => count($permissions),
            'permissions' => $permissions,
            'sensitive' => array_values(array_map(static fn (array $p): string => $p['key'], array_filter($permissions, static fn (array $p): bool => $p['sensitive']))),
            'menu_visible' => $visible,
            'menu_hidden' => $hidden,
            'menu_visible_count' => count($visibleKeys),
            'menu_total' => count(FeatureRegistry::menuKeys()),
            'warnings' => $warnings,
        ];
    }

    /** CSV (separatore «;», UTF-8 con BOM per Excel) della matrice permessi × profili. */
    public static function matrixCsv(): string
    {
        $m = self::matrix();
        $out = [array_merge(['Area', 'Permesso', 'Sigla', 'Sensibile', 'Cosa sblocca'], array_map(static fn (array $c): string => $c['label'], $m['profiles']))];
        foreach ($m['areas'] as $area) {
            foreach ($area['rows'] as $r) {
                $out[] = array_merge(
                    [$area['label'], $r['label'], $r['key'], $r['sensitive'] ? 'sì' : 'no', $r['detail']],
                    array_map(static fn (array $c): string => $r['system_only'] ? 'solo super_admin' : ($r['in'][$c['key']] ? 'sì' : ''), $m['profiles']),
                );
            }
        }

        return self::csv($out);
    }

    /** CSV con una riga per ogni utente del back-office (non super_admin): ruolo, profilo, permessi, menu, 2FA, avvisi. */
    public static function usersCsv(): string
    {
        $out = [['Nome', 'Email', 'Profilo corrispondente', 'Permessi', 'Di cui sensibili', 'Voci di menu visibili', 'Voci di menu totali', '2FA', 'Account', 'Avvisi', 'Elenco permessi']];
        foreach (self::backofficeUsers() as $u) {
            $r = self::forUser($u);
            $out[] = [
                $u->name, $u->email,
                $r['profile'] !== null ? AccessProfiles::labelOf($r['profile']) : 'personalizzato',
                $r['permission_count'], count($r['sensitive']), $r['menu_visible_count'], $r['menu_total'],
                $r['two_factor'] ? 'sì' : 'no', $r['suspended'] ? 'sospeso' : 'attivo',
                implode(' | ', $r['warnings']),
                implode(', ', array_map(static fn (array $p): string => $p['key'], $r['permissions'])),
            ];
        }

        return self::csv($out);
    }

    /**
     * @param  list<list<scalar|null>>  $rows
     */
    private static function csv(array $rows): string
    {
        $f = fopen('php://temp', 'r+');
        fwrite($f, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            // niente formule: una cella che inizia con = + - @ in Excel verrebbe eseguita
            fputcsv($f, array_map(static fn ($v): string => preg_match('/^[=+\-@]/', (string) $v) === 1 ? "'".$v : (string) $v, $row), ';');
        }
        rewind($f);

        return (string) stream_get_contents($f);
    }
}
