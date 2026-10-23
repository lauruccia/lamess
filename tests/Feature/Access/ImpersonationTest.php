<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Impersonation;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_enters_as_user_and_comes_back_with_audit(): void
    {
        $admin = User::factory()->role('super_admin')->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->startSession();
        $request = request();
        $request->setLaravelSession($this->app['session.store']);
        Impersonation::start($request, $admin, $target);
        $this->assertSame($target->id, auth()->id());
        $this->assertSame($admin->id, Impersonation::impersonator($request)->id);

        $this->post('/area/torna-admin')->assertRedirect('/admin');
        $this->assertSame($admin->id, auth()->id());
        $this->assertSame(1, AuditEntry::where('action', 'impersonation.started')->count());
        $this->assertSame(1, AuditEntry::where('action', 'impersonation.stopped')->count());
    }

    public function test_only_superadmin_can_impersonate_and_not_themselves(): void
    {
        $admin = User::factory()->role('admin_network')->create();
        $super = User::factory()->role('super_admin')->create();
        $target = User::factory()->create();

        $this->assertFalse(Impersonation::canImpersonate($admin, $target));
        $this->assertFalse(Impersonation::canImpersonate($super, $super));
        $this->assertTrue(Impersonation::canImpersonate($super, $target));
    }

    public function test_sensitive_actions_are_blocked_while_impersonating_and_banner_is_shown(): void
    {
        $admin = User::factory()->role('super_admin')->create();
        $target = User::factory()->create();

        $this->actingAs($admin)->withSession([Impersonation::SESSION_KEY => $admin->id]);
        $this->actingAs($target);

        $this->from('/area/profilo')->post('/area/profilo/password', ['current_password' => 'x', 'password' => 'Nuova-Password-123!', 'password_confirmation' => 'Nuova-Password-123!'])
            ->assertRedirect('/area/profilo')->assertSessionHasErrors('impersonation');
        $this->get('/area')->assertOk()->assertSee('Torna al mio account');
    }

    public function test_stop_without_impersonation_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create())->post('/area/torna-admin')->assertForbidden();
    }
}
