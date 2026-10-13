<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Domain\Career\CareerProgress;
use App\Domain\Consultants\Consultant360;
use App\Domain\Features\Feature;
use App\Domain\Payouts\StatementService;
use App\Domain\Periods\PeriodService;
use App\Domain\Plan\ActiveStatus;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Pages\LegacyPlanPage;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\TestCase;

/** Interruttore «nascondi il vecchio piano»: sparisce dalla vista, resta nel database. */
final class LegacyPlanHiddenTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private Consultant $a;

    private Consultant $b;

    private Period $period;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->period = $this->openPeriod('2026-10');
        $this->a = $this->consultant('A', null, 'DIAMOND');
        $this->b = $this->consultant('B', $this->a, 'CRYSTAL');
        $this->sale($this->period, $this->b, '8000');
        app(PeriodService::class)->calculate($this->period);
        $this->super = User::factory()->role()->create();
    }

    private function hide(): void
    {
        $this->actingAs($this->super);
        LegacyPlan::hide('Test');
    }

    public function test_one_click_from_the_page_hides_and_shows_again_and_is_logged(): void
    {
        $this->actingAs($this->super);
        $this->assertFalse(LegacyPlan::hidden());

        Livewire::test(LegacyPlanPage::class)->callAction('toggle')->assertNotified();
        $this->assertTrue(LegacyPlan::hidden());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'settings.updated')->count());

        Livewire::test(LegacyPlanPage::class)->callAction('toggle')->assertNotified();
        $this->assertFalse(LegacyPlan::hidden());
    }

    public function test_the_admin_who_manages_the_plan_can_toggle_but_not_other_admins(): void
    {
        $manager = User::factory()->role('admin')->create();
        $manager->givePermissionTo(['plan.view', 'plan.manage']);
        $other = User::factory()->role('admin')->create();
        $other->givePermissionTo(['plan.view']);

        $this->assertTrue(LegacyPlan::canToggle($manager));
        $this->assertFalse(LegacyPlan::canToggle($other));

        $this->actingAs($manager);
        Livewire::test(LegacyPlanPage::class)->callAction('toggle');
        $this->assertTrue(LegacyPlan::hidden());

        $this->actingAs($other)->get('/admin/piano-precedente')->assertForbidden();
    }

    public function test_hiding_removes_the_legacy_screens_even_for_the_super_admin_but_deletes_nothing(): void
    {
        $movements = PvMovement::count();
        $lines = DB::table('commission_lines')->count();
        $this->assertGreaterThan(0, $movements);

        $this->actingAs($this->super)->get('/admin/pv-movements')->assertOk();
        $this->hide();

        $this->get('/admin/pv-movements')->assertForbidden();
        $this->assertFalse(Feature::enabled('consultant360.tab.volumes', $this->super));
        $this->assertFalse(Feature::enabled('consultant360.counter.movements', $this->super));
        $this->assertFalse(Feature::enabled('backoffice.consultants_list.volumes', $this->super));
        $this->assertFalse(Feature::enabled('backoffice.page.confronto_qualifiche', $this->super));
        $this->assertTrue(Feature::enabled('consultant360.tab.sales', $this->super), 'gli ordini restano');

        $this->assertSame($movements, PvMovement::count());
        $this->assertSame($lines, DB::table('commission_lines')->count());

        LegacyPlan::show();
        $this->get('/admin/pv-movements')->assertOk();
        $this->assertTrue(Feature::enabled('consultant360.tab.volumes', $this->super));
    }

    public function test_the_incaricato_card_has_no_points_left_when_hidden(): void
    {
        $this->hide();
        $this->get('/admin/consultants/'.$this->b->id)->assertOk()
            ->assertDontSee('Volumi e rank')->assertDontSee('Ordini e PV')->assertDontSee('Movimenti PV')->assertDontSee(' PVF')->assertDontSee('PR pers.')
            ->assertSee('Ordini')->assertSee('Commissioni');

        $sales = (new Consultant360($this->b))->tab('sales');
        $this->assertCount(1, $sales['tables']);
        $this->assertNotContains('PV', $sales['tables'][0]['columns']);
    }

    public function test_old_bonus_types_are_collapsed_under_a_neutral_label(): void
    {
        $this->hide();
        $com = (new Consultant360($this->a))->tab('commissions');
        $typesShown = collect($com['tables'][0]['rows'])->pluck(1)->unique()->all();
        foreach (['Team', 'Matching', 'Rank Bonus', 'Fast Start', 'Rata Rank Bonus'] as $word) {
            $this->assertNotContains($word, $typesShown);
        }

        $this->assertSame(['direct' => '10.00', LegacyPlan::NEUTRAL_LABEL => '35.00'], LegacyPlan::collapseBonusTypes(['direct' => '10.00', 'team' => '20.00', 'matching' => '15.00']));
        $this->assertSame(LegacyPlan::NEUTRAL_LABEL, LegacyPlan::bonusLabel('fsb', 'Fast Start Bonus'));
        $this->assertSame('Compensi indiretti', LegacyPlan::bonusLabel('indirect', 'Compensi indiretti'));
    }

    public function test_the_monthly_statement_shows_one_neutral_group_instead_of_the_old_bonuses(): void
    {
        $this->period->refresh();
        $svc = app(StatementService::class);
        DB::table('periods')->where('id', $this->period->id)->update(['status' => 'closed', 'official_run_id' => DB::table('calculation_runs')->where('period_id', $this->period->id)->max('id')]);
        DB::table('commission_lines')->where('run_id', DB::table('calculation_runs')->where('period_id', $this->period->id)->max('id'))->update(['status' => 'locked']);
        $period = Period::find($this->period->id);

        $before = collect($svc->build($period, $this->a)['groups'])->pluck('label')->all();
        $this->assertContains('Team Bonus', $before);

        $this->hide();
        $after = $svc->build($period, $this->a);
        $labels = collect($after['groups'])->pluck('label')->all();
        $this->assertContains(LegacyPlan::NEUTRAL_LABEL, $labels);
        foreach (['Team Bonus', 'Matching Bonus', 'Rank Bonus', 'Fast Start Bonus', 'Rata Rank Bonus'] as $word) {
            $this->assertNotContains($word, $labels);
        }
        $this->assertSame(
            collect($svc->build($period, $this->a)['groups'])->sum(fn ($g) => (float) $g['accrued']),
            (float) $after['accrued'],
            'il totale non cambia: cambiano solo le etichette',
        );
    }

    public function test_career_progress_drops_points_and_old_bonus_lines(): void
    {
        $with = app(CareerProgress::class)->forConsultant($this->b->id);
        $this->assertStringContainsString('PR', implode(' ', $with['todo']));

        $this->hide();
        $p = (new CareerProgress)->forConsultant($this->b->id);
        $text = implode(' ', $p['todo']);
        foreach (['PR', 'Rank Bonus', 'Direct', 'Matching'] as $word) {
            $this->assertStringNotContainsString($word, $text);
        }
        $this->assertArrayNotHasKey('matching', $p['gates']);
        $this->assertSame([], $p['bonuses']['installments']);
        $this->assertFalse(ActiveStatus::applies());
    }

    public function test_legacy_plan_versions_disappear_from_the_plan_screen(): void
    {
        $this->actingAs($this->super)->get('/admin/comp-plan-versions')->assertOk();
        $legacyName = (string) DB::table('comp_plan_versions')->orderBy('id')->value('name');
        $this->assertNotSame('', $legacyName);

        $this->hide();
        $this->get('/admin/comp-plan-versions')->assertOk()->assertDontSee($legacyName);
    }
}
