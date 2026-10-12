<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Actor;
use App\Domain\Access\Roles;
use App\Domain\Access\UserAdministration;
use App\Filament\Pages\AuditVerificationPage;
use App\Filament\Pages\SystemSettingsPage;
use App\Filament\Resources\AuditLogs\AuditLogResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\Scopes\HidesSuperAdmins;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Criterio di uscita Fase 1: un admin non vede né trova il super_admin. */
final class SuperAdminVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = User::factory()->role(Roles::SUPER_ADMIN)->create(['name' => 'Laura Super', 'email' => 'super@example.test']);
        $this->admin = User::factory()->role(Roles::ADMIN)->create(['email' => 'admin@example.test']);
        $this->admin->syncPermissions(Roles::grantable());
    }

    public function test_admin_does_not_see_superadmin_in_queries(): void
    {
        $this->actingAs($this->admin);

        $this->assertNull(User::where('email', 'super@example.test')->first());
        $this->assertNull(User::find($this->super->id));
        $this->assertSame(0, User::where('name', 'like', '%Super%')->count());
        $this->assertFalse(User::pluck('email')->contains('super@example.test'));
        $this->assertSame(0, User::role(Roles::SUPER_ADMIN)->count());
        $this->assertFalse(User::all()->contains('id', $this->super->id));
    }

    public function test_superadmin_sees_everyone(): void
    {
        $this->actingAs($this->super);

        $this->assertNotNull(User::find($this->super->id));
        $this->assertNotNull(User::find($this->admin->id));
    }

    public function test_route_binding_returns_404_for_admin(): void
    {
        Route::middleware('web')->get('/_test/utente/{user}', fn (User $user) => $user->email);

        $this->actingAs($this->admin)->get('/_test/utente/'.$this->super->id)->assertNotFound();
        $this->actingAs($this->admin)->get('/_test/utente/'.$this->admin->id)->assertOk();
        $this->actingAs($this->super)->get('/_test/utente/'.$this->super->id)->assertOk();
    }

    public function test_admin_cannot_open_users_settings_or_audit_pages_even_with_every_permission(): void
    {
        $this->actingAs($this->admin);

        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/impostazioni')->assertForbidden();
        $this->get('/admin/audit-logs')->assertForbidden();
        $this->get('/admin/verifica-audit')->assertForbidden();

        $this->assertFalse(UserResource::canViewAny());
        $this->assertFalse(SystemSettingsPage::canAccess());
        $this->assertFalse(AuditVerificationPage::canAccess());
        $this->assertFalse(AuditLogResource::canViewAny());
    }

    public function test_superadmin_can_open_users_settings_and_audit_pages(): void
    {
        $this->actingAs($this->super);

        $this->get('/admin/users')->assertOk();
        $this->get('/admin/impostazioni')->assertOk();
        $this->get('/admin/audit-logs')->assertOk();
        $this->get('/admin/verifica-audit')->assertOk();
    }

    public function test_users_list_for_superadmin_includes_superadmin_but_admin_cannot_list_it(): void
    {
        $this->actingAs($this->super);
        $this->assertTrue(UserResource::getEloquentQuery()->where('email', 'super@example.test')->exists());
    }

    public function test_actor_label_hides_superadmin_identity(): void
    {
        $this->assertSame('Amministrazione', Actor::label($this->super));
        $this->assertSame('admin@example.test', Actor::label($this->admin));
        $this->assertSame('sistema', Actor::label(null));
    }

    public function test_user_administration_refuses_non_grantable_permissions(): void
    {
        $this->expectException(ValidationException::class);
        (new UserAdministration)->createAdmin('X', 'x@example.test', ['users.manage']);
    }

    public function test_user_administration_never_edits_a_superadmin(): void
    {
        $svc = new UserAdministration;
        $this->actingAs($this->super);

        foreach ([
            fn () => $svc->resetPassword($this->super),
            fn () => $svc->resetTwoFactor($this->super),
            fn () => $svc->revokeBackoffice($this->super),
            fn () => $svc->setPermissions($this->super, []),
        ] as $call) {
            try {
                $call();
                $this->fail('Il super_admin non deve essere modificabile da qui.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertTrue($this->super->fresh()->isSuperAdmin());
    }

    public function test_a_superadmin_can_change_name_and_email_of_a_superadmin_but_an_admin_cannot(): void
    {
        $svc = new UserAdministration;
        $this->actingAs($this->super);
        $svc->updateProfile($this->super, 'Nuovo nome', 'nuova@example.test');
        $this->assertSame('Nuovo nome', $this->super->fresh()->name);

        $this->actingAs(User::factory()->role('admin')->create());
        $this->expectException(ValidationException::class);
        $svc->updateProfile($this->super, 'Altro', 'altro@example.test');
    }

    public function test_admin_role_cannot_be_given_superadmin_through_permissions(): void
    {
        $this->assertNotContains('users.manage', Roles::grantable());
        $this->assertFalse($this->admin->fresh()->isSuperAdmin());
    }

    /** Nessun codice dell'app deve leggere users saltando lo scope, tranne l'import da Odoo. */
    public function test_no_raw_queries_on_users_table_outside_allowlist(): void
    {
        $allow = ['OdooImporter.php', 'UserAdministration.php', 'AuditLog.php', 'HidesSuperAdmins.php'];
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php' || in_array($file->getFilename(), $allow, true)) {
                continue;
            }
            if (preg_match("/DB::table\(\s*['\"]users['\"]\s*\)/", (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders, 'DB::table(\'users\') aggira lo scope che nasconde il super_admin.');
    }

    public function test_scope_class_is_registered_on_user_model(): void
    {
        $this->actingAs($this->admin);
        $this->assertSame(0, User::withoutGlobalScope(HidesSuperAdmins::class)->count() - User::count() - 1);
    }
}
