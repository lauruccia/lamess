<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Periods\PeriodService;
use App\Domain\Periods\PeriodStateException;
use App\Filament\Resources\CompPlanVersions\Pages\CreateCompPlanVersion;
use App\Filament\Resources\CompPlanVersions\Pages\ViewCompPlanVersion;
use App\Filament\Resources\Consultants\Pages\CreateConsultant;
use App\Filament\Resources\Consultants\Pages\ViewConsultant;
use App\Filament\Resources\Periods\Pages\ListPeriods;
use App\Filament\Resources\Periods\Pages\ViewPeriod;
use App\Filament\Resources\PvMovements\Pages\CreatePvMovement;
use App\Models\CommissionLine;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class AdminPanelTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role()->create();
    }

    public function test_non_admin_cannot_access_panel(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_admin_pages_render(): void
    {
        $this->plan();
        $this->actingAs($this->admin());
        foreach (['', '/periods', '/pv-movements', '/pv-movements/create', '/comp-plan-versions', '/comp-plan-versions/create', '/consultants', '/consultants/create'] as $path) {
            $this->get('/admin'.$path)->assertOk();
        }
    }

    public function test_period_cycle_from_the_panel(): void
    {
        $plan = $this->plan();
        $this->actingAs($this->admin());
        Livewire::test(ListPeriods::class)->callAction('open', ['code' => '2026-10'])->assertNotified();
        $p = Period::where('code', '2026-10')->firstOrFail();
        $a = $this->consultant('A', null, 'DIAMOND');
        $this->sale($p, $a, '100');

        Livewire::test(ViewPeriod::class, ['record' => $p->getKey()])
            ->callAction('live')->callAction('runtime')->callAction('close');

        $this->assertSame(Period::CLOSED, $p->refresh()->status);
        $this->assertSame('30.00', number_format((float) CommissionLine::where('status', 'locked')->sum('amount'), 2, '.', ''));
        $this->assertSame($plan->id, $p->comp_plan_version_id);
    }

    public function test_opening_a_period_twice_shows_error_not_exception(): void
    {
        $this->plan();
        $this->actingAs($this->admin());
        $this->openPeriod('2026-10');
        Livewire::test(ListPeriods::class)->callAction('open', ['code' => '2026-10'])->assertNotified('Impossibile aprire il periodo');
        $this->assertSame(1, Period::count());
    }

    public function test_movement_is_recorded_through_ledger_rules(): void
    {
        $this->actingAs($this->admin());
        $p = $this->openPeriod('2026-10');
        $c = $this->consultant('A');
        Livewire::test(CreatePvMovement::class)->fillForm([
            'period_id' => $p->id, 'consultant_id' => $c->id, 'kind' => 'pvf', 'type' => 'sale', 'pv' => 100,
            'event_at' => '2026-10-10 08:00:00', 'status' => 'confirmed',
        ])->call('create')->assertHasNoFormErrors();
        $this->assertSame(1, PvMovement::count());
        // data fuori dal periodo → rifiutata
        Livewire::test(CreatePvMovement::class)->fillForm([
            'period_id' => $p->id, 'consultant_id' => $c->id, 'kind' => 'pvf', 'type' => 'sale', 'pv' => 100,
            'event_at' => '2026-11-10 08:00:00', 'status' => 'confirmed',
        ])->call('create');
        $this->assertSame(1, PvMovement::count());
    }

    public function test_plan_version_activation_archives_previous_and_rejects_invalid_plan(): void
    {
        $old = $this->plan();
        $this->actingAs($this->admin());
        $draft = CompPlanVersion::createFromArray('Bozza', $old->data);
        Livewire::test(ViewCompPlanVersion::class, ['record' => $draft->getKey()])->callAction('activate', ['effective_from' => now()->toDateString(), 'reason' => 'Nuove percentuali']);
        $this->assertSame('active', $draft->refresh()->status);
        $this->assertSame('archived', $old->refresh()->status);
        $this->assertNotNull($draft->approved_by);

        Livewire::test(CreateCompPlanVersion::class)
            ->fillForm(['name' => 'Rotta', 'ranks' => [['code' => 'X', 'name' => 'X', 'sequence' => 0, 'pr_required' => '10', 'direct_pct' => '15', 'rank_bonus' => '0']]])
            ->call('create')->assertHasFormErrors();
    }

    public function test_simulation_does_not_write_anything(): void
    {
        $base = $this->plan();
        $this->actingAs($this->admin());
        $p = $this->openPeriod('2026-10', $base);
        $a = $this->consultant('A', null, 'DIAMOND');
        $b = $this->consultant('P', $a, 'PEARL');
        $this->sale($p, $b, '1000');
        $data = $base->data;
        $data['ranks'][1]['direct_pct'] = '19';
        $alt = CompPlanVersion::createFromArray('Pearl 19%', $data);
        $lines = CommissionLine::count();

        Livewire::test(ViewCompPlanVersion::class, ['record' => $alt->getKey()])->callAction('simulate', ['period_id' => $p->id])->assertNotified();
        $this->assertSame($lines, CommissionLine::count());
    }

    public function test_consultant_enrollment_and_termination_from_panel(): void
    {
        $this->actingAs($this->admin());
        $s = $this->consultant('S');
        $x = $this->consultant('X', $s);
        $k = $this->consultant('K', $x);

        Livewire::test(CreateConsultant::class)->fillForm(['code' => 'N1', 'first_name' => 'Nuovo', 'last_name' => 'Incaricato', 'since' => '2026-10-02 08:00:00'])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(Consultant::where('code', 'N1')->exists());

        Livewire::test(ViewConsultant::class, ['record' => $x->getKey()])->callAction('terminate', ['reason' => 'dimissioni']);
        $this->assertSame('terminated', $x->refresh()->status);
        $this->assertSame($s->id, $k->refresh()->sponsor_id);
    }

    public function test_movement_of_closed_period_is_immutable(): void
    {
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $m = $this->sale($p, $a, '100');
        $svc = app(PeriodService::class);
        $svc->calculate($p, 'official');
        $svc->close($p);
        $this->expectException(PeriodStateException::class);
        $m->update(['status' => 'cancelled']);
    }
}
