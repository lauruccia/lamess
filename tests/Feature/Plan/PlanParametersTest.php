<?php

declare(strict_types=1);

namespace Tests\Feature\Plan;

use App\Domain\Access\Roles;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Plan\PlanFormMapper;
use App\Domain\Plan\PlanVersionService;
use App\Filament\Resources\CompPlanVersions\Pages\CreateCompPlanVersion;
use App\Filament\Resources\CompPlanVersions\Pages\EditCompPlanVersion;
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

/** Fase 2 — parametri del piano: valori iniziali, modulo, data di efficacia, log prima/dopo, permessi. */
final class PlanParametersTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->role(Roles::SUPER_ADMIN)->create();
    }

    private function auditFor(string $action): ?array
    {
        $row = AuditEntry::where('action', $action)->orderByDesc('id')->first();

        $c = $row?->changes;

        return is_string($c) ? json_decode($c, true) : $c;
    }

    public function test_initial_values_from_lamess_config_are_in_the_plan(): void
    {
        $plan = PlanLoader::prelaunch();
        $this->assertSame('15', (string) $plan->minPayout);
        $this->assertSame('5000', (string) $plan->scnThreshold);
        $this->assertSame(7, $plan->moveNodeWindowDays);
        $this->assertSame('100', (string) $plan->activityThreshold); // Minimum Monthly PR (Activity)
    }

    public function test_old_versions_without_new_settings_still_load_with_defaults(): void
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/plan-prelaunch-2026-07-07.json')), true);
        unset($data['settings']['min_payout'], $data['settings']['scn_threshold'], $data['settings']['move_node_window_days']);
        $v = CompPlanVersion::createFromArray('Vecchia', $data);
        $this->assertSame('15', (string) $v->plan()->minPayout);
    }

    public function test_form_round_trip_changes_nothing(): void
    {
        $data = PlanLoader::prelaunch() ? json_decode((string) file_get_contents(base_path('config/lamess/plan-prelaunch-2026-07-07.json')), true) : [];
        $back = PlanFormMapper::toData(PlanFormMapper::toForm($data), $data);
        $this->assertSame([], PlanVersionService::diff($data, $back));
        PlanConfig::fromArray($back); // valido
    }

    public function test_draft_edit_logs_previous_and_new_values_and_requires_reason(): void
    {
        $this->actingAs($this->superAdmin());
        $base = $this->plan();
        $draft = CompPlanVersion::createFromArray('Bozza', $base->data);

        Livewire::test(EditCompPlanVersion::class, ['record' => $draft->getKey()])
            ->fillForm(['settings.min_payout' => '25', 'reason' => ''])->call('save')->assertHasFormErrors(['reason']);

        Livewire::test(EditCompPlanVersion::class, ['record' => $draft->getKey()])
            ->fillForm(['settings.min_payout' => '25', 'reason' => 'Soglia concordata'])->call('save')->assertHasNoFormErrors();

        $this->assertSame('25', $draft->refresh()->data['settings']['min_payout']);
        $changes = $this->auditFor('plan.updated');
        $this->assertEquals(['prima' => '15', 'dopo' => '25'], $changes['settings.min_payout']);
        $this->assertSame('Soglia concordata', AuditEntry::where('action', 'plan.updated')->latest('id')->value('reason'));
    }

    private function planAdminUser(): User
    {
        $u = User::factory()->role(Roles::ADMIN)->create();
        $u->givePermissionTo(['plan.view', 'plan.manage']);

        return $u;
    }

    public function test_active_version_cannot_be_edited_by_an_admin(): void
    {
        $this->actingAs($this->planAdminUser());
        $active = $this->plan();
        $this->expectException(ValidationException::class);
        app(PlanVersionService::class)->updateDraft($active, 'x', $active->data, null, 'prova');
    }

    public function test_super_admin_can_edit_even_the_active_version(): void
    {
        $this->actingAs($this->superAdmin());
        $active = $this->plan();
        $data = $active->data;
        $data['settings']['min_payout'] = '30';

        $changes = app(PlanVersionService::class)->updateDraft($active, $active->name, $data, null, 'Correzione');

        $this->assertArrayHasKey('settings.min_payout', $changes);
        $this->assertSame('active', $active->refresh()->status);
        $this->assertSame('30', (string) $active->data['settings']['min_payout']);
    }

    public function test_super_admin_can_activate_in_the_past_and_restore_an_archived_version(): void
    {
        $this->actingAs($this->superAdmin());
        $old = $this->plan();
        $draft = CompPlanVersion::createFromArray('B', $old->data);
        $svc = app(PlanVersionService::class);

        $svc->activate($draft, new DateTimeImmutable('-3 days'), 'Recupero');
        $this->assertSame('active', $draft->refresh()->status);
        $this->assertSame('archived', $old->refresh()->status);

        $svc->activate($old, new DateTimeImmutable('today'), 'Ritorno al piano precedente');
        $this->assertSame('active', $old->refresh()->status);
        $this->assertSame('archived', $draft->refresh()->status);
    }

    public function test_activation_with_future_date_schedules_and_old_plan_stays_in_force(): void
    {
        $this->actingAs($this->superAdmin());
        $old = $this->plan();
        $draft = CompPlanVersion::createFromArray('Dal mese prossimo', $old->data);
        $future = now()->addDays(10)->startOfDay();

        Livewire::test(ViewCompPlanVersion::class, ['record' => $draft->getKey()])
            ->callAction('activate', ['effective_from' => $future->toDateString(), 'reason' => 'Nuovo listino']);

        $this->assertSame('scheduled', $draft->refresh()->status);
        $this->assertSame('active', $old->refresh()->status);
        $this->assertSame($old->id, CompPlanVersion::inForce()->id);
        $this->assertSame($draft->id, CompPlanVersion::inForce($future->toDateTimeImmutable())->id);

        $this->travelTo($future->addHour());
        $this->assertSame(1, app(PlanVersionService::class)->promoteDue());
        $this->assertSame('active', $draft->refresh()->status);
        $this->assertSame('archived', $old->refresh()->status);
    }

    public function test_activation_in_the_past_or_without_reason_is_refused(): void
    {
        $this->actingAs($this->planAdminUser());
        $draft = CompPlanVersion::createFromArray('B', $this->plan()->data);
        try {
            app(PlanVersionService::class)->activate($draft, new DateTimeImmutable('-3 days'), 'x');
            $this->fail('data nel passato accettata');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('effective_from', $e->errors());
        }
        $this->expectException(ValidationException::class);
        app(PlanVersionService::class)->activate($draft, new DateTimeImmutable('today'), ' ');
    }

    public function test_activation_is_logged_with_effective_date(): void
    {
        $this->actingAs($this->superAdmin());
        $draft = CompPlanVersion::createFromArray('B', $this->plan()->data);
        app(PlanVersionService::class)->activate($draft, new DateTimeImmutable('today'), 'Via');
        $c = $this->auditFor('plan.activated');
        $this->assertSame(now()->format('Y-m-d'), $c['efficace_dal']);
        $this->assertSame('active', $c['stato']['dopo']);
    }

    public function test_period_opens_with_plan_in_force_on_its_first_day(): void
    {
        $this->actingAs($this->superAdmin());
        $old = $this->plan();
        $old->update(['effective_from' => '2026-01-01']);
        $draft = CompPlanVersion::createFromArray('Da novembre', $old->data);
        $draft->update(['status' => 'scheduled', 'effective_from' => '2026-11-01']);

        $this->assertSame($old->id, CompPlanVersion::inForceForPeriod('2026-10')->id);
        $this->assertSame($draft->id, CompPlanVersion::inForceForPeriod('2026-11')->id);
    }

    public function test_plan_manage_permission_is_needed_and_only_super_admin_can_grant_it(): void
    {
        $this->plan();
        $without = User::factory()->role(Roles::ADMIN)->create();
        $this->actingAs($without)->get('/admin/comp-plan-versions/create')->assertForbidden();

        $with = User::factory()->role(Roles::ADMIN)->create();
        $with->givePermissionTo(['plan.view', 'plan.manage']);
        $this->actingAs($with)->get('/admin/comp-plan-versions/create')->assertOk();

        // L'admin non può aprire la gestione utenti, quindi non può concedere permessi a sé o ad altri.
        $this->actingAs($with)->get('/admin/users')->assertStatus(403);
        $this->assertNotContains('plan.manage', Roles::SYSTEM_ONLY);
        $this->assertContains('plan.manage', Roles::grantable());
    }

    public function test_create_form_starts_from_plan_in_force(): void
    {
        $this->actingAs($this->superAdmin());
        $this->plan();
        Livewire::test(CreateCompPlanVersion::class)
            ->assertFormSet(['settings.min_payout' => '15', 'settings.scn_threshold' => '5000'])
            ->fillForm(['name' => 'Nuova', 'settings.min_payout' => '20'])->call('create')->assertHasNoFormErrors();
        $v = CompPlanVersion::where('name', 'Nuova')->firstOrFail();
        $this->assertSame('20', $v->data['settings']['min_payout']);
        $this->assertNotNull($this->auditFor('plan.created'));
    }
}
