<?php

declare(strict_types=1);

namespace Tests\Feature\Career;

use App\Domain\Career\CareerProgress;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\PeriodService;
use App\Domain\Plan\LegacyPlan;
use App\Models\CompPlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Piano Incaricati: «cosa manca per la prossima qualifica» con clienti, Pearl, colonne e struttura. */
final class IncaricatiCareerProgressTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    public function test_next_rank_requirements_are_listed_even_with_the_old_plan_hidden(): void
    {
        config(['lamess.'.LegacyPlan::SETTING => true]);
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous'];
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $period = $this->openPeriod(now('Europe/Rome')->format('Y-m'), $plan);
        $this->shipping();
        $product = $this->product('A', '123.00');
        $sponsor = $this->activeSponsor();
        $svc = app(OrderService::class);
        foreach ([$this->buyer($sponsor), $this->buyer($sponsor)] as $u) {
            $svc->markPaid($svc->place($u, [$product->id => 1], 'IT', 'transfer', $this->billing()));
        }
        app(PeriodService::class)->calculate($period);

        $p = (new CareerProgress)->forConsultant($sponsor->consultant->id ?? $sponsor->id);
        $this->assertNotNull($p['next']);
        $clients = collect($p['requirements'])->firstWhere('label', 'Clienti personali');
        $this->assertNotNull($clients, 'requisito clienti personali');
        $this->assertSame(2, $clients['counted']);
        $this->assertFalse($clients['ok']);
        $this->assertStringContainsString('clienti personali', implode(' ', $p['todo']));
        $this->assertStringNotContainsString(' PR', implode(' ', $p['todo']));

        $html = view('partials.career-progress', ['p' => $p])->render();
        $this->assertStringContainsString('Clienti personali', $html);
        $this->assertStringNotContainsString('PR di gruppo', $html);
    }

    public function test_next_direct_tier_skips_tiers_with_the_same_percentage(): void
    {
        config(['lamess.'.LegacyPlan::SETTING => true]);
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous'];
        $data['kosmos']['direct_tiers'] = [
            ['min_clients' => 0, 'pct' => 10], ['min_clients' => 3, 'pct' => 20], ['min_clients' => 6, 'pct' => 20], ['min_clients' => 9, 'pct' => 25],
        ];
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $period = $this->openPeriod(now('Europe/Rome')->format('Y-m'), $plan);
        $this->shipping();
        $product = $this->product('A', '123.00');
        $sponsor = $this->activeSponsor();
        $svc = app(OrderService::class);
        foreach ([$this->buyer($sponsor), $this->buyer($sponsor), $this->buyer($sponsor)] as $u) {
            $svc->markPaid($svc->place($u, [$product->id => 1], 'IT', 'transfer', $this->billing()));
        }
        app(PeriodService::class)->calculate($period);

        $p = (new CareerProgress)->forConsultant($sponsor->consultant->id ?? $sponsor->id);
        $next = collect($p['kosmos']['tiers'])->firstWhere('is_next', true);
        $this->assertNotNull($next);
        $this->assertSame(9, $next['min_clients'], 'il tier a 6 clienti paga come quello attuale (20%): non è il prossimo');
        $this->assertStringContainsString('sale dal 20% al 25%', implode(' ', $p['todo']));
    }
}
