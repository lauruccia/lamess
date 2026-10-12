<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Chi è cliente nel piano Incaricati: dal database, con gli importi in euro degli ordini dello shop. */
final class ClientRuleTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;
    use ShopDb;

    private Period $period;

    private Consultant $sponsor;

    private function setUpPlan(): void
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous', 'kosmos_rank_maintenance' => 'none'];
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $this->period = $this->openPeriod(now('Europe/Rome')->format('Y-m'), $plan);
        $this->shipping();
        $this->sponsor = $this->consultant('S', null, null, now('Europe/Rome')->startOfMonth()->addMinutes(5)->format('Y-m-d H:i'));
    }

    /** @param array<int, int> $lines prodotto => quantità */
    private function order(array $lines): int
    {
        $svc = app(OrderService::class);
        $order = $svc->place($this->buyer($this->sponsor), $lines, 'IT', 'transfer', $this->billing());
        $svc->markPaid($order);

        return $order->id;
    }

    /** @param array<string, string> $kosmos */
    private function planWith(array $kosmos): PlanConfig
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous'];
        $data['kosmos'] = array_replace($data['kosmos'], $kosmos);

        return PlanConfig::fromArray($data);
    }

    /** @return array<string, array<string, \DateTimeImmutable>> */
    private function firstPurchases(PlanConfig $plan): array
    {
        return SnapshotBuilder::build($this->period, $plan)->customerFirstPurchase;
    }

    public function test_an_order_below_the_minimum_amount_does_not_make_a_client(): void
    {
        $this->setUpPlan();
        $small = $this->product('S1', '100.00'); // 122,00 € IVA inclusa
        $this->order([$small->id => 1]);

        $this->assertSame([], $this->firstPurchases($this->planWith(['client_rule' => 'min_amount', 'client_min_amount' => '150'])));
        $this->assertCount(1, $this->firstPurchases($this->planWith(['client_rule' => 'any_order']))[(string) $this->sponsor->id], 'qualunque ordine basta');
    }

    public function test_the_lines_of_one_order_add_up_and_a_full_price_box_is_enough(): void
    {
        $this->setUpPlan();
        $a = $this->product('S1', '100.00');
        $b = $this->product('S2', '30.00'); // 122,00 + 36,60 = 158,60 €
        $box = $this->product('BOX', '123.00'); // 150,06 €
        $this->order([$a->id => 1, $b->id => 1]);
        $this->order([$box->id => 1]);
        $plan = $this->planWith(['client_rule' => 'min_amount', 'client_min_amount' => '150']);

        $this->assertCount(2, $this->firstPurchases($plan)[(string) $this->sponsor->id], 'due clienti distinti, ciascuno con un ordine da almeno 150 €');
        $stricter = $this->planWith(['client_rule' => 'min_amount', 'client_min_amount' => '155']);
        $this->assertCount(1, $this->firstPurchases($stricter)[(string) $this->sponsor->id], 'con 155 € solo l’ordine da 158,60');
    }
}
