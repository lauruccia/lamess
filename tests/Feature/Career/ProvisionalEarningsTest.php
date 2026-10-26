<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\EarningsSummary;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\NightlyRecalculation;
use App\Domain\Periods\PeriodService;
use App\Domain\Plan\LegacyPlan;
use App\Models\CompPlanVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Compensi provvisori del mese aperto: sempre aggiornati, mai presentati come definitivi. */
final class ProvisionalEarningsTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

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

        return [$sponsor, $period, $order];
    }

    public function test_open_month_earnings_are_flagged_provisional_with_the_calculation_time(): void
    {
        [$sponsor] = $this->setUpMonth();

        $e = app(EarningsSummary::class)->for($sponsor->id);

        $this->assertFalse($e['closed']);
        $this->assertNotNull($e['calculated_at'], 'serve l\'ora dell\'ultimo calcolo per scrivere «aggiornato il…»');
        $this->assertGreaterThan(0, $e['accrued']);
    }

    public function test_the_branch_page_shows_the_provisional_amount_not_as_final(): void
    {
        [$sponsor] = $this->setUpMonth();
        $user = User::factory()->create(['name' => $sponsor->name]);
        $sponsor->update(['user_id' => $user->id]);

        $html = $this->actingAs($user)->get('/area/rete')->assertOk()->getContent();

        $this->assertStringContainsString('provvisorio', $html);
        $this->assertStringContainsString('Non definitivo', $html);
        $this->assertStringNotContainsString('importo definitivo', $html);
    }

    public function test_a_return_refreshes_the_live_calculation_right_after_the_response(): void
    {
        [, , $order] = $this->setUpMonth();
        Cache::forget(NightlyRecalculation::CACHE_KEY);

        app(\App\Domain\Orders\ReturnService::class)->refund($order, [$order->items()->firstOrFail()->id => 1], 'prova');
        $this->app->terminate();

        $this->assertNotNull(Cache::get(NightlyRecalculation::CACHE_KEY), 'dopo un reso i compensi provvisori si ricalcolano subito');
    }
}
