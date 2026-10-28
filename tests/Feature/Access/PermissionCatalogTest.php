<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\PermissionCatalog;
use App\Domain\Access\Roles;
use App\Domain\Access\UserAdministration;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_roles_declare_the_same_permissions(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(Roles::PERMISSIONS), array_keys(PermissionCatalog::PERMISSIONS));
        foreach (PermissionCatalog::PERMISSIONS as $perm => $def) {
            $this->assertArrayHasKey($def['area'], PermissionCatalog::AREAS, "{$perm}: area sconosciuta");
            foreach ($def['requires'] ?? [] as $needed) {
                $this->assertArrayHasKey($needed, PermissionCatalog::PERMISSIONS, "{$perm}: richiede un permesso sconosciuto {$needed}");
            }
        }
    }

    public function test_every_gated_resource_uses_declared_permissions(): void
    {
        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) as $file) {
            $class = 'App\\Filament\\Resources\\'.basename(dirname($file)).'\\'.basename($file, '.php');
            if (! in_array(\App\Filament\Concerns\GatedByPermission::class, class_uses($class), true)) {
                continue;
            }
            $view = (new \ReflectionMethod($class, 'viewPermission'))->invoke(null);
            $manage = (new \ReflectionMethod($class, 'managePermission'))->invoke(null);
            $this->assertArrayHasKey($view, Roles::PERMISSIONS, "{$class}: permesso di lettura sconosciuto");
            $this->assertArrayHasKey($manage, Roles::PERMISSIONS, "{$class}: permesso di gestione sconosciuto");
        }
    }

    public function test_every_manage_permission_requires_its_view_permission(): void
    {
        foreach (array_keys(PermissionCatalog::PERMISSIONS) as $perm) {
            if (str_ends_with($perm, '.manage') && array_key_exists(str_replace('.manage', '.view', $perm), PermissionCatalog::PERMISSIONS)) {
                $this->assertContains(str_replace('.manage', '.view', $perm), PermissionCatalog::requiresOf($perm), "{$perm} deve richiedere il suo «vedere»");
            }
        }
    }

    public function test_every_preset_profile_is_closed_under_its_requirements(): void
    {
        foreach (Roles::MATRIX as $role => $perms) {
            $this->assertEqualsCanonicalizing(PermissionCatalog::close($perms), $perms, "{$role}: mancano permessi richiesti");
        }
    }

    public function test_close_adds_required_permissions_even_through_a_chain(): void
    {
        $this->assertSame(['network.view', 'kyc.view', 'kyc.manage'], PermissionCatalog::close(['kyc.manage']));
        $this->assertSame(['kpi.view'], PermissionCatalog::close(['kpi.view']));
        $this->assertSame(['network.view', 'network.manage'], PermissionCatalog::close(['network.manage', 'network.view']));
    }

    public function test_form_state_round_trips(): void
    {
        $perms = ['network.view', 'orders.view', 'orders.manage', 'features.manage'];
        $state = PermissionCatalog::splitByArea($perms);

        $this->assertSame(['network.view'], $state['rete']);
        $this->assertSame(['orders.view', 'orders.manage'], $state['vendite']);
        $this->assertEqualsCanonicalizing($perms, PermissionCatalog::flatten($state));
        $this->assertSame([], PermissionCatalog::flatten(null));
    }

    public function test_every_grantable_permission_appears_in_exactly_one_area_list(): void
    {
        $listed = [];
        foreach (array_keys(PermissionCatalog::AREAS) as $area) {
            array_push($listed, ...PermissionCatalog::grantableIn($area));
        }
        $this->assertEqualsCanonicalizing(Roles::grantable(), $listed);
        $this->assertNotContains('users.manage', $listed);
    }

    public function test_service_adds_the_required_view_permission_and_creates_missing_rows(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $svc = new UserAdministration;
        $admin = $svc->createAdmin('A', 'a@example.test', ['catalog.manage'])['user'];
        $this->assertEqualsCanonicalizing(['catalog.view', 'catalog.manage'], $admin->getAllPermissions()->pluck('name')->all());

        Permission::query()->where('name', 'content.view')->delete();
        $svc->setPermissions($admin, ['content.manage']);
        $this->assertEqualsCanonicalizing(['content.view', 'content.manage'], $admin->fresh()->getAllPermissions()->pluck('name')->all());
    }

    public function test_read_only_permission_sees_the_list_but_cannot_change_anything(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $viewer = (new UserAdministration)->createAdmin('V', 'v@example.test', ['catalog.view'])['user'];
        $this->actingAs($viewer);

        $this->get('/admin/products')->assertOk();
        $this->assertTrue(ProductResource::canViewAny());
        $this->assertFalse(ProductResource::canCreate());
        $this->get('/admin/negozio-pagamenti')->assertForbidden();
    }

    public function test_manage_alone_still_opens_the_list_for_admins_created_before_the_view_permissions(): void
    {
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $legacy = User::factory()->role(Roles::ADMIN)->create();
        $legacy->givePermissionTo(Permission::findOrCreate('catalog.manage', 'web'));

        $this->actingAs($legacy);
        $this->assertFalse($legacy->can('catalog.view'));
        $this->get('/admin/products')->assertOk();
    }
}
