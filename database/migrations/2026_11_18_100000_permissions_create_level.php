<?php

use App\Domain\Access\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Terzo livello di permesso («creare ed eliminare», `area.create`). Per non togliere nulla a nessuno, chi ha già
 * `area.manage` (ruoli, profili personalizzati e permessi diretti degli utenti) riceve anche `area.create`.
 * Sul sito live senza terminale si applica a mano l'SQL del registro aggiornamenti (2026-11-05).
 */
return new class extends Migration
{
    private const AREAS = ['network', 'kyc', 'fees', 'periods', 'orders', 'catalog', 'payouts', 'support', 'compliance', 'content'];

    public function up(): void
    {
        Roles::sync();
        $ids = DB::table('permissions')->where('guard_name', 'web')->pluck('id', 'name');
        foreach (self::AREAS as $area) {
            $manage = $ids["{$area}.manage"] ?? null;
            $create = $ids["{$area}.create"] ?? null;
            if ($manage === null || $create === null) {
                continue;
            }
            foreach (DB::table('role_has_permissions')->where('permission_id', $manage)->pluck('role_id') as $roleId) {
                DB::table('role_has_permissions')->insertOrIgnore(['permission_id' => $create, 'role_id' => $roleId]);
            }
            foreach (DB::table('model_has_permissions')->where('permission_id', $manage)->get(['model_type', 'model_id']) as $row) {
                DB::table('model_has_permissions')->insertOrIgnore(['permission_id' => $create, 'model_type' => $row->model_type, 'model_id' => $row->model_id]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', array_map(static fn (string $a): string => "{$a}.create", self::AREAS))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
