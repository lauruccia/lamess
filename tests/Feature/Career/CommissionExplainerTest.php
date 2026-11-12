<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\CommissionExplainer;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\PeriodService;
use App\Domain\Plan\LegacyPlan;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Order;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** «Da cosa è dovuto ogni compenso»: ogni riga ha una frase con ordine, cliente, regola e motivo. */
final class CommissionExplainerTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    /** @return array{0:Consultant,1:Period,2:Order} */
    private function setUpMonth(): array
    {
        config(['lamess.'.LegacyPlan::SETTING => true]);
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $period = $this->openPeriod(now('Europe/Rome')->format('Y-m'), $plan);
        $this->shipping();
        $product = $this->product('A', '123.00');
        $sponsor = $this->activeSponsor();
        $svc = app(OrderService::class);
        $order = $svc->markPaid($svc->place($this->buyer($sponsor), [$product->id => 1], 'IT', 'transfer', $this->billing()));
        app(PeriodService::class)->calculate($period);

        return [$sponsor, $period->refresh(), $order];
    }

    public function test_a_direct_compensation_names_the_order_the_customer_and_the_rule(): void
    {
        [$sponsor, $period, $order] = $this->setUpMonth();

        $d = app(CommissionExplainer::class)->for($sponsor, $period);

        $this->assertNotNull($d['run_id']);
        $direct = collect($d['groups'])->firstWhere('type', 'direct');
        $this->assertNotNull($direct, 'il venditore ha un compenso diretto');
        $line = $direct['lines'][0];
        $this->assertStringContainsString((string) $order->number, $line['sentence'], 'la frase dice da quale ordine nasce');
        $this->assertStringContainsString('Compenso', $line['sentence']);
        $this->assertSame('accrued', $line['status']);
        $this->assertFalse($line['warning']);
        $this->assertGreaterThan(0.0, $d['accrued']);
        $this->assertNotEmpty($d['rules'], 'le regole del piano sono elencate');
    }

    public function test_a_movement_left_without_its_order_is_flagged(): void
    {
        [$sponsor, $period, $order] = $this->setUpMonth();
        // come dopo l'eliminazione dell'ordine: il movimento resta confermato ma senza ordine (FK «set null»)
        PvMovement::where('order_id', $order->id)->update(['order_id' => null, 'order_item_id' => null]);
        app(PeriodService::class)->calculate($period);

        $d = app(CommissionExplainer::class)->for($sponsor, $period->refresh());

        $line = collect($d['groups'])->firstWhere('type', 'direct')['lines'][0];
        $this->assertTrue($line['warning']);
        $this->assertStringContainsString('NESSUN ORDINE COLLEGATO', $line['sentence']);
    }

    public function test_the_detail_page_opens_for_a_super_admin(): void
    {
        [$sponsor, $period] = $this->setUpMonth();
        $admin = User::factory()->role('super_admin')->create();

        $html = $this->actingAs($admin)
            ->get('/admin/compenso-dettaglio?incaricato='.$sponsor->id.'&mese='.$period->code)
            ->assertOk()->getContent();

        $this->assertStringContainsString($sponsor->name, $html);
        $this->assertStringContainsString('Compenso diretto', $html);
    }

    public function test_the_monthly_list_links_each_row_to_its_detail(): void
    {
        [$sponsor, $period] = $this->setUpMonth();
        $admin = User::factory()->role('super_admin')->create();

        $html = $this->actingAs($admin)->get('/admin/compensi-mese?mese='.$period->code)->assertOk()->getContent();

        $this->assertStringContainsString('compenso-dettaglio?incaricato='.$sponsor->id, $html);
        $this->assertStringContainsString('Dettaglio', $html);
    }

    /** Come Sebastiano: salta un livello perché ha 1 cliente e ne servono 2. */
    private function addSkippedLine(Consultant $c, Period $period): void
    {
        $runId = (int) DB::table('calculation_runs')->where('period_id', $period->id)->where('status', 'completed')->max('id');
        $movement = PvMovement::query()->firstOrFail();
        DB::table('commission_lines')->insert([
            'run_id' => $runId, 'period_id' => $period->id, 'beneficiary_id' => $c->id, 'line_key' => 'indirect:'.$movement->id.':'.$c->id.':skip',
            'bonus_type' => 'indirect', 'base_amount' => '1000.00', 'pct' => '0.0400', 'amount' => '40.00', 'source_movement_id' => $movement->id,
            'source_consultant_id' => $c->id, 'level' => 2, 'engine_status' => 'excluded', 'reason' => 'requisiti_livello_2: clienti 1/2, Pearl 1° liv. 5/1',
            'explanation' => json_encode(['clients' => 1, 'min_clients' => 2, 'pearl_first_level' => 5, 'min_pearl' => 1, 'compressed' => true, 'depth' => 2]),
            'decisions' => '[]', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_skipped_levels_are_listed_with_what_is_missing_and_never_counted_as_earned(): void
    {
        [$sponsor, $period] = $this->setUpMonth();
        $before = app(CommissionExplainer::class)->for($sponsor, $period)['accrued'];
        $this->addSkippedLine($sponsor, $period);

        $d = app(CommissionExplainer::class)->for($sponsor, $period->refresh());

        $this->assertEquals($before, $d['accrued'], 'una riga saltata non entra nel maturato');
        $this->assertEquals(40.0, $d['missing']['total']);
        $lv = $d['missing']['levels'][0];
        $this->assertSame(2, $lv['level']);
        $this->assertSame(1, $lv['lack_clients']);
        $this->assertSame(0, $lv['lack_pearl']);
        $this->assertSame('1 cliente in più', $lv['lack_text']);
        $this->assertStringContainsString('mancano 1 cliente personale (ne ha 1, ne servono 2)', $d['missing']['lines'][0]['sentence']);
    }

    public function test_the_detail_page_shows_the_missing_requirements_section(): void
    {
        [$sponsor, $period] = $this->setUpMonth();
        $this->addSkippedLine($sponsor, $period);
        $admin = User::factory()->role('super_admin')->create();

        $html = $this->actingAs($admin)
            ->get('/admin/compenso-dettaglio?incaricato='.$sponsor->id.'&mese='.$period->code)
            ->assertOk()->getContent();

        $this->assertStringContainsString('Compensi non maturati: requisiti mancanti', $html);
        $this->assertStringContainsString('1 cliente in più', $html);
    }

    public function test_the_monthly_list_shows_who_has_only_unearned_compensations(): void
    {
        [$sponsor, $period] = $this->setUpMonth();
        // Come Giovanni Rocca: nessun compenso maturato nel mese, solo righe non maturate per requisiti mancanti.
        $only = $this->activeSponsor('LM00077');
        $this->addSkippedLine($only, $period);
        $admin = User::factory()->role('super_admin')->create();

        $html = $this->actingAs($admin)->get('/admin/compensi-mese?mese='.$period->code.'&cerca=LM00077')->assertOk()->getContent();

        $this->assertStringContainsString('compenso-dettaglio?incaricato='.$only->id, $html, 'compare anche con «Solo chi ha importi» acceso');
        $this->assertStringContainsString('Non maturato', $html);
        $this->assertStringContainsString('40,00', $html);
        $this->assertStringNotContainsString('Nessun incaricato con importi', $html);
    }

    public function test_with_compression_the_missing_section_says_the_level_moves_up(): void
    {
        [$sponsor, $period] = $this->setUpMonth();
        $this->addSkippedLine($sponsor, $period);
        $admin = User::factory()->role('super_admin')->create();

        $this->assertTrue(app(CommissionExplainer::class)->for($sponsor, $period)['compression']);
        $this->actingAs($admin)->get('/admin/compenso-dettaglio?incaricato='.$sponsor->id.'&mese='.$period->code)
            ->assertOk()->assertSee('con la compressione passano al primo incaricato');
    }

    public function test_without_compression_the_missing_section_says_the_level_stays_unpaid(): void
    {
        [$sponsor, $period] = $this->setUpMonth();
        $this->addSkippedLine($sponsor, $period);
        $admin = User::factory()->role('super_admin')->create();
        // Compressione spenta nel piano: il livello non passa a nessuno.
        $plan = CompPlanVersion::query()->findOrFail($period->comp_plan_version_id);
        $data = $plan->data;
        $data['policies']['kosmos_compression'] = 'none';
        $plan->update(['data' => $data]);

        $this->assertFalse(app(CommissionExplainer::class)->for($sponsor, $period->refresh())['compression']);
        $this->actingAs($admin)->get('/admin/compenso-dettaglio?incaricato='.$sponsor->id.'&mese='.$period->code)
            ->assertOk()->assertSee('non passano a nessun altro')->assertDontSee('con la compressione passano');
    }

    public function test_the_consultant_opens_the_own_detail_page(): void
    {
        [$sponsor, $period, $order] = $this->setUpMonth();
        $user = User::factory()->create();
        $sponsor->update(['user_id' => $user->id]);

        $html = $this->actingAs($user)->get('/area/compensi?mese='.$period->code)->assertOk()->getContent();

        $this->assertStringContainsString('Dettaglio compensi', $html);
        $this->assertStringContainsString('Compensi diretti', $html);
        $this->assertStringContainsString('Bonus istantaneo', $html);
        $this->assertStringContainsString((string) $order->number, $html);
        $this->assertStringNotContainsString('/admin/', $html, 'nessun link al back-office');
    }

    public function test_a_user_without_consultant_profile_gets_404_on_the_detail_page(): void
    {
        $this->actingAs(User::factory()->create())->get('/area/compensi')->assertNotFound();
    }

    public function test_the_own_view_hides_back_office_hints(): void
    {
        [$sponsor, $period, $order] = $this->setUpMonth();
        PvMovement::where('order_id', $order->id)->update(['order_id' => null, 'order_item_id' => null]);
        app(PeriodService::class)->calculate($period);

        $line = collect(app(CommissionExplainer::class)->for($sponsor, $period->refresh(), true)['groups'])->firstWhere('type', 'direct')['lines'][0];

        $this->assertFalse($line['warning']);
        $this->assertNull($line['order_url']);
        $this->assertStringNotContainsString('NESSUN ORDINE COLLEGATO', $line['sentence']);
    }
}
