<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\AccessProfiles;
use App\Domain\Access\Roles;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AccessProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
    }

    public function test_preset_profiles_are_always_listed(): void
    {
        $all = AccessProfiles::all();
        foreach (array_keys(Roles::PRESET_LABELS) as $key) {
            $this->assertTrue($all[$key]['builtin']);
        }
        $this->assertEqualsCanonicalizing(Roles::preset('admin_finance'), $all['admin_finance']['permissions']);
    }

    public function test_save_creates_a_custom_profile_with_required_permissions(): void
    {
        $key = AccessProfiles::save('Contabilità ordini', ['orders.manage', 'kpi.view']);

        $this->assertSame('profilo:Contabilità ordini', $key);
        $this->assertSame(['kpi.view', 'orders.view', 'orders.manage'], AccessProfiles::permissions($key));
        $this->assertSame('Contabilità ordini (personalizzato)', AccessProfiles::options()[$key]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'access.profile_created')->count());
    }

    public function test_save_can_rename_and_change_permissions_and_logs_both_states(): void
    {
        $key = AccessProfiles::save('Assistenza base', ['support.view']);
        $new = AccessProfiles::save('Assistenza avanzata', ['support.manage'], $key);

        $this->assertSame('profilo:Assistenza avanzata', $new);
        $this->assertFalse(AccessProfiles::exists($key));
        $this->assertSame(['support.view', 'support.manage'], AccessProfiles::permissions($new));
        $this->assertSame(1, Role::query()->where('name', 'like', 'profilo:%')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'access.profile_updated')->count());
    }

    public function test_invalid_profiles_are_refused(): void
    {
        $cases = [
            'nome corto' => fn () => AccessProfiles::save('ab', ['kpi.view']),
            'nome come un preimpostato' => fn () => AccessProfiles::save('rete e anagrafiche', ['kpi.view']),
            'caratteri strani' => fn () => AccessProfiles::save('<script>x', ['kpi.view']),
            'permesso di sistema' => fn () => AccessProfiles::save('Con utenti', ['users.manage']),
            'permesso inventato' => fn () => AccessProfiles::save('Con inventato', ['cose.fai']),
        ];
        foreach ($cases as $label => $run) {
            try {
                $run();
                $this->fail("Doveva essere rifiutato: {$label}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, Role::query()->where('name', 'like', 'profilo:%')->count());
    }

    public function test_duplicate_names_are_refused_ignoring_case(): void
    {
        AccessProfiles::save('Marketing', ['content.view']);
        $this->expectException(ValidationException::class);
        AccessProfiles::save('marketing', ['content.view']);
    }

    public function test_preset_profiles_cannot_be_changed_or_deleted(): void
    {
        foreach ([fn () => AccessProfiles::save('Altro nome', ['kpi.view'], 'board'), fn () => AccessProfiles::delete('board')] as $run) {
            try {
                $run();
                $this->fail('Doveva essere rifiutato.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_delete_removes_only_the_profile_not_the_permissions_already_given(): void
    {
        $key = AccessProfiles::save('Temporaneo', ['kpi.view']);
        $admin = User::factory()->role(Roles::ADMIN)->create();
        $admin->syncPermissions(AccessProfiles::permissions($key));

        AccessProfiles::delete($key);

        $this->assertFalse(AccessProfiles::exists($key));
        $this->assertTrue($admin->fresh()->can('kpi.view'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'access.profile_deleted')->count());
    }

    public function test_only_the_super_admin_can_manage_profiles(): void
    {
        $this->actingAs(User::factory()->role(Roles::ADMIN)->create());
        $this->expectException(AuthorizationException::class);
        AccessProfiles::save('Furbo', ['kpi.view']);
    }

    public function test_a_custom_profile_never_opens_the_backoffice_and_survives_a_role_sync(): void
    {
        $key = AccessProfiles::save('Solo modello', ['kpi.view']);
        $user = User::factory()->create();
        $this->assertFalse($user->isBackoffice());
        $this->assertNotContains($key, Roles::backoffice());

        Roles::sync();
        $this->assertTrue(AccessProfiles::exists($key));
    }

    public function test_matching_finds_the_profile_with_the_same_permissions(): void
    {
        $this->assertSame('board', AccessProfiles::matching(Roles::preset('board')));
        $key = AccessProfiles::save('Solo KPI', ['kpi.view']);
        $this->assertSame($key, AccessProfiles::matching(['kpi.view']));
        $this->assertNull(AccessProfiles::matching(['kpi.view', 'orders.view']));
    }
}
