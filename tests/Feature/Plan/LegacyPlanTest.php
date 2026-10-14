<?php

declare(strict_types=1);

namespace Tests\Feature\Plan;

use App\Domain\Features\Feature;
use App\Domain\Network\NetworkService;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Pages\LegacyPlanPage;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Interruttore «nascondi il piano precedente»: cosa cambia, chi può usarlo e che non si cancella nulla. */
final class LegacyPlanTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function withUser(Consultant $c): User
    {
        $u = User::factory()->create(['name' => $c->name]);
        $c->update(['user_id' => $u->id]);

        return $u;
    }

    public function test_the_old_plan_is_visible_by_default(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        $this->assertFalse(LegacyPlan::hidden());
        $this->assertTrue(LegacyPlan::visible());
        $this->assertTrue(Feature::enabled('consultant360.tab.volumes'));
        $this->assertSame('Team', LegacyPlan::bonusLabel('team', 'Team'));
    }

    public function test_hiding_removes_the_old_plan_features_for_everybody_and_showing_brings_them_back(): void
    {
        $this->actingAs($super = User::factory()->role('super_admin')->create());

        LegacyPlan::hide('Il nuovo piano parte il 1° novembre');
        $this->assertTrue(LegacyPlan::hidden());
        $this->assertFalse(Feature::enabled('consultant360.tab.volumes'), 'tab del vecchio piano: sparisce anche per il super_admin');
        $this->assertFalse(Feature::enabled('consultant360.counter.movements'));
        $this->assertTrue(Feature::enabled('consultant360.tab.network'), 'le funzioni comuni restano');
        $this->assertTrue(Feature::enabled('consultant360.tab.volumes', $super) === false);

        LegacyPlan::show();
        $this->assertTrue(LegacyPlan::visible());
        $this->assertTrue(Feature::enabled('consultant360.tab.volumes'));
    }

    public function test_the_setting_change_is_written_to_the_audit_log_with_the_reason(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        LegacyPlan::hide('Piano nuovo dal 1° novembre');

        $row = DB::table('audit_logs')->where('reason', 'Piano nuovo dal 1° novembre')->first();
        $this->assertNotNull($row, 'il cambio è registrato con il motivo');
        $this->assertStringContainsString('legacy_plan_hidden', (string) json_encode($row));
    }

    public function test_only_the_super_admin_can_use_the_switch(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());
        $this->assertTrue(LegacyPlanPage::canAccess());
        LegacyPlan::hide();
        $this->assertTrue(LegacyPlan::hidden());
        LegacyPlan::show();

        foreach (['admin_compplan', 'support'] as $role) {
            $this->actingAs(User::factory()->role($role)->create());
            $this->assertFalse(LegacyPlanPage::canAccess(), $role);
        }
        $this->expectException(HttpException::class);
        LegacyPlan::show();
    }

    public function test_the_page_button_hides_and_shows_again(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());

        Livewire::test(LegacyPlanPage::class)->callAction('toggle')->assertNotified();
        $this->assertTrue(LegacyPlan::hidden());

        Livewire::test(LegacyPlanPage::class)->callAction('toggle')->assertNotified();
        $this->assertTrue(LegacyPlan::visible());
    }

    public function test_hiding_does_not_delete_or_recalculate_anything(): void
    {
        $this->plan();
        $this->openPeriod('2026-10');
        $before = ['plans' => CompPlanVersion::count(), 'periods' => DB::table('periods')->count(), 'movements' => DB::table('pv_movements')->count(), 'lines' => DB::table('commission_lines')->count()];

        $this->actingAs(User::factory()->role('super_admin')->create());
        LegacyPlan::hide();
        LegacyPlan::show();

        $this->assertSame($before, ['plans' => CompPlanVersion::count(), 'periods' => DB::table('periods')->count(), 'movements' => DB::table('pv_movements')->count(), 'lines' => DB::table('commission_lines')->count()]);
    }

    public function test_the_old_plan_bonuses_are_collected_under_a_neutral_label_while_hidden(): void
    {
        $byType = ['direct' => '100.00', 'team' => '10.50', 'matching' => '4.50', 'rank' => '20.00', 'indirect' => '8.00'];
        $this->assertSame($byType, LegacyPlan::collapseBonusTypes($byType), 'visibile: nessuna modifica');

        config(['lamess.legacy_plan_hidden' => true]);
        $out = LegacyPlan::collapseBonusTypes($byType);
        $this->assertSame(['direct' => '100.00', 'indirect' => '8.00', LegacyPlan::NEUTRAL_LABEL => '35.00'], $out, 'team, matching e rank in una voce sola; direct e indirect restano');
        $this->assertSame(LegacyPlan::NEUTRAL_LABEL, LegacyPlan::bonusLabel('team', 'Team'));
        $this->assertSame('Diretti', LegacyPlan::bonusLabel('direct', 'Diretti'));
    }

    public function test_the_consultant_area_shows_points_only_while_the_old_plan_is_visible(): void
    {
        $this->plan();
        $this->openPeriod('2026-10');
        $a = $this->consultant('A');
        $this->consultant('B', $a);
        app(NetworkService::class)->rebuildClosure();
        $this->actingAs($this->withUser($a));

        $this->get('/area/rete')->assertOk()->assertSee('PVF personali del mese')->assertSee('PR del mese');

        config(['lamess.legacy_plan_hidden' => true]);
        $this->get('/area/rete')->assertOk()->assertDontSee('PVF personali del mese')->assertDontSee('PR del mese')->assertSee('Incaricato B');
    }
}
