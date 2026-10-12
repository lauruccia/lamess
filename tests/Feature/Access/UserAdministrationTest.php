<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Roles;
use App\Domain\Access\UserAdministration;
use App\Domain\Audit\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private UserAdministration $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new UserAdministration;
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
    }

    public function test_create_admin_with_preset_permissions(): void
    {
        $perms = Roles::preset('admin_finance');
        $r = $this->svc->createAdmin('Cliente', 'cliente@example.test', $perms);

        $this->assertTrue($r['user']->hasRole(Roles::ADMIN));
        $this->assertFalse($r['user']->isSuperAdmin());
        $this->assertTrue(Hash::check($r['password'], $r['user']->password));
        foreach ($perms as $p) {
            $this->assertTrue($r['user']->hasPermissionTo($p));
        }
        $this->assertFalse($r['user']->hasPermissionTo('users.manage'));
    }

    public function test_created_admin_has_only_granted_permissions(): void
    {
        $r = $this->svc->createAdmin('A', 'a@example.test', []);
        $this->assertSame([], $r['user']->getAllPermissions()->pluck('name')->all());
    }

    public function test_duplicate_email_is_refused(): void
    {
        $this->svc->createAdmin('A', 'a@example.test', []);
        $this->expectException(ValidationException::class);
        $this->svc->createAdmin('B', 'a@example.test', []);
    }

    public function test_weak_password_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc->createAdmin('A', 'a@example.test', [], 'corta');
    }

    public function test_permissions_can_be_changed_and_revoked(): void
    {
        $perms = Roles::grantable();
        $u = $this->svc->createAdmin('A', 'a@example.test', [])['user'];
        $this->svc->setPermissions($u, [$perms[0]]);
        $this->assertTrue($u->fresh()->hasPermissionTo($perms[0]));

        $this->svc->revokeBackoffice($u);
        $this->assertFalse($u->fresh()->hasRole(Roles::ADMIN));
        $this->assertSame([], $u->fresh()->getAllPermissions()->pluck('name')->all());
        $this->assertNotNull(User::find($u->id), 'L\'utente non viene cancellato.');
    }

    public function test_reset_password_changes_it_and_never_logs_it(): void
    {
        $u = $this->svc->createAdmin('A', 'a@example.test', [])['user'];
        $new = $this->svc->resetPassword($u);

        $this->assertTrue(Hash::check($new, $u->fresh()->password));
        $this->assertStringNotContainsString($new, DB::table('audit_logs')->get()->map(fn ($r) => json_encode($r))->implode('|'));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'user.password_reset_by_admin')->count());
    }

    public function test_reset_two_factor_clears_secret(): void
    {
        $u = $this->svc->createAdmin('A', 'a@example.test', [])['user'];
        $u->forceFill(['app_authentication_secret' => 'ABCDEF'])->save();
        $this->svc->resetTwoFactor($u);
        $this->assertNull($u->fresh()->app_authentication_secret);
        $this->assertTrue(AuditLog::verify()->ok);
    }

    public function test_superadmin_command_creates_only_one(): void
    {
        $this->artisan('lamess:superadmin', ['email' => 'altro@example.test', '--name' => 'X', '--password' => 'Password-Lunga-123'])->assertFailed();
        $this->assertSame(1, User::withoutGlobalScopes()->role(Roles::SUPER_ADMIN)->count());

        $this->artisan('lamess:superadmin', ['email' => 'altro@example.test', '--name' => 'X', '--password' => 'Password-Lunga-123', '--consenti-altro' => true])->assertSuccessful();
        $this->assertSame(2, User::withoutGlobalScopes()->role(Roles::SUPER_ADMIN)->count());
    }

    public function test_admin_command_never_creates_superadmin(): void
    {
        $this->artisan('lamess:admin-user', ['email' => 'x@example.test', '--role' => 'super_admin'])->assertFailed();
        $this->assertSame(1, User::withoutGlobalScopes()->role(Roles::SUPER_ADMIN)->count());
    }
}
