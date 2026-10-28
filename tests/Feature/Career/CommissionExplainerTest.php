<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\CommissionExplainer;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\PeriodService;
use App\Domain\Plan\LegacyPlan;
use App\Models\CompPlanVersion;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** «Da cosa è dovuto ogni compenso»: ogni riga ha una frase con ordine, cliente, regola e motivo. */
final class CommissionExplainerTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    /** @return array{0:\App\Models\Consultant,1:Period,2:\App\Models\Order} */
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
}
