<?php

declare(strict_types=1);

namespace Tests\Feature\Plan;

use App\Domain\Access\Roles;
use App\Domain\Plan\PlanVersionService;
use App\Filament\Resources\CompPlanVersions\Pages\ViewCompPlanVersion;
use App\Models\AuditEntry;
use App\Models\CompPlanVersion;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Approvazione a quattro occhi della pubblicazione del piano: il secondo amministratore decide, mai chi ha chiesto. */
final class PlanFourEyesTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.plan_four_eyes' => true]);
        Roles::sync();
    }

    private function draft(): CompPlanVersion
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/plan-prelaunch-2026-07-07.json')), true);

        return CompPlanVersion::createFromArray('Nuove percentuali', $data);
    }

    private function planAdmin(): User
    {
        $u = User::factory()->role(Roles::ADMIN)->create();
        $u->givePermissionTo(['plan.view', 'plan.manage']);

        return $u;
    }

    public function test_publish_with_four_eyes_waits_for_approval_and_does_not_change_the_plan_in_force(): void
    {
        $v = $this->draft();
        $this->actingAs($this->planAdmin());

        $v = app(PlanVersionService::class)->publish($v, new DateTimeImmutable('today'), 'Nuovo listino');

        $this->assertSame(CompPlanVersion::PENDING_APPROVAL, $v->status);
        $this->assertNull(CompPlanVersion::inForce());
        $this->assertNotNull($v->requested_by_id);
    }

    public function test_the_requester_cannot_approve_his_own_request(): void
    {
        $v = $this->draft();
        $this->actingAs($this->planAdmin());
        $svc = app(PlanVersionService::class);
        $svc->publish($v, new DateTimeImmutable('today'), 'Mio piano');

        $this->expectException(ValidationException::class);
        $svc->approve($v->refresh());
    }

    public function test_super_admin_publishes_alone_without_a_second_admin(): void
    {
        $v = $this->draft();
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());

        $v = app(PlanVersionService::class)->publish($v, new DateTimeImmutable('today'), 'Decisione del superadmin');

        $this->assertSame(CompPlanVersion::ACTIVE, $v->status);
    }

    public function test_a_second_admin_approves_and_the_version_becomes_active(): void
    {
        $v = $this->draft();
        $svc = app(PlanVersionService::class);
        $this->actingAs($this->planAdmin());
        $svc->publish($v, new DateTimeImmutable('today'), 'Nuovo listino');

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        $v = $svc->approve($v->refresh(), 'Ok');

        $this->assertSame(CompPlanVersion::ACTIVE, $v->status);
        $this->assertSame($v->id, CompPlanVersion::inForce()?->id);
        $this->assertTrue(AuditEntry::where('action', 'plan.approved')->exists());
        $this->assertTrue(AuditEntry::where('action', 'plan.approval_requested')->exists());
    }

    public function test_future_effective_date_is_scheduled_on_approval(): void
    {
        $v = $this->draft();
        $svc = app(PlanVersionService::class);
        $this->actingAs($this->planAdmin());
        $svc->publish($v, new DateTimeImmutable('+10 days'), 'Dal mese prossimo');
        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());

        $this->assertSame(CompPlanVersion::SCHEDULED, $svc->approve($v->refresh())->status);
    }

    public function test_activate_cannot_bypass_the_second_admin(): void
    {
        $this->actingAs($this->planAdmin());
        $this->expectException(ValidationException::class);
        app(PlanVersionService::class)->activate($this->draft(), new DateTimeImmutable('today'), 'Salto');
    }

    public function test_a_pending_version_cannot_be_edited_and_rejection_returns_it_to_draft(): void
    {
        $v = $this->draft();
        $svc = app(PlanVersionService::class);
        $this->actingAs($this->planAdmin());
        $svc->publish($v, new DateTimeImmutable('today'), 'x');

        try {
            $svc->updateDraft($v->refresh(), 'Altro nome', $v->data, null, 'x');
            $this->fail('Una versione in approvazione non si modifica.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->actingAs(User::factory()->role(Roles::SUPER_ADMIN)->create());
        try {
            $svc->reject($v->refresh(), ' ');
            $this->fail('Il motivo del rifiuto è obbligatorio.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->assertSame(CompPlanVersion::DRAFT, $svc->reject($v->refresh(), 'Aliquote da rivedere')->status);
    }

    public function test_requester_can_withdraw_but_a_third_party_cannot(): void
    {
        $v = $this->draft();
        $svc = app(PlanVersionService::class);
        $a = $this->planAdmin();
        $this->actingAs($a);
        $svc->publish($v, new DateTimeImmutable('today'), 'x');

        $this->actingAs($this->planAdmin());
        try {
            $svc->withdraw($v->refresh());
            $this->fail('Un terzo non può ritirare la richiesta.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->actingAs($a);
        $this->assertSame(CompPlanVersion::DRAFT, $svc->withdraw($v->refresh())->status);
    }

    public function test_switching_off_four_eyes_restores_direct_activation(): void
    {
        config(['lamess.plan_four_eyes' => false]);
        $this->actingAs($this->planAdmin());
        $v = app(PlanVersionService::class)->publish($this->draft(), new DateTimeImmutable('today'), 'Subito');

        $this->assertSame(CompPlanVersion::ACTIVE, $v->status);
    }

    public function test_panel_actions_follow_the_permissions(): void
    {
        $v = $this->draft();
        $requester = $this->planAdmin();
        $this->actingAs($requester);
        Livewire::test(ViewCompPlanVersion::class, ['record' => $v->getKey()])
            ->callAction('activate', ['effective_from' => now()->toDateString(), 'reason' => 'Via']);
        $this->assertSame(CompPlanVersion::PENDING_APPROVAL, $v->refresh()->status);

        // senza il permesso di approvare l'azione non è consentita
        Livewire::test(ViewCompPlanVersion::class, ['record' => $v->getKey()])->assertActionHidden('activate');

        $approver = User::factory()->role(Roles::ADMIN)->create();
        $approver->givePermissionTo(['plan.view', 'plan.approve']);
        $this->actingAs($approver);
        Livewire::test(ViewCompPlanVersion::class, ['record' => $v->getKey()])->callAction('approve', ['note' => 'ok']);

        $this->assertSame(CompPlanVersion::ACTIVE, $v->refresh()->status);
    }
}
