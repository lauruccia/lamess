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
use App\Models\PvMovement;
use Brick\Math\BigDecimal;
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

    public function test_a_total_above_the_minimum_makes_a_client_even_with_only_accessories(): void
    {
        $this->setUpPlan();
        $acc1 = $this->product('A1', '70.00'); // 85,40 €
        $acc2 = $this->product('A2', '60.00'); // 73,20 € → totale 158,60 €
        $this->order([$acc1->id => 1, $acc2->id => 1]);

        $this->assertCount(1, $this->firstPurchases($this->planWith(['client_rule' => 'min_amount', 'client_min_amount' => '150']))[(string) $this->sponsor->id]);
    }

    public function test_a_product_that_makes_a_client_counts_below_the_minimum_amount(): void
    {
        $this->setUpPlan();
        $cheap = $this->product('C1', '40.00', ['makes_client' => true]); // 48,80 €
        $other = $this->product('C2', '40.00');
        $this->order([$cheap->id => 1]);
        $this->order([$other->id => 1]);
        $plan = $this->planWith(['client_rule' => 'min_amount', 'client_min_amount' => '150']);

        $this->assertCount(1, $this->firstPurchases($plan)[(string) $this->sponsor->id], 'conta solo l’acquisto del prodotto che rende cliente');
        $this->assertTrue((bool) PvMovement::query()->whereNotNull('order_item_id')->orderBy('id')->first()->makes_client);
    }

    public function test_the_choice_is_frozen_on_the_order_and_a_later_change_does_not_rewrite_it(): void
    {
        $this->setUpPlan();
        $p = $this->product('F1', '40.00', ['makes_client' => true]);
        $this->order([$p->id => 1]);
        $p->update(['makes_client' => false]);
        $plan = $this->planWith(['client_rule' => 'min_amount', 'client_min_amount' => '150']);

        $this->assertCount(1, $this->firstPurchases($plan)[(string) $this->sponsor->id], 'l’ordine già fatto resta com’era');
        $this->order([$p->id => 1]);
        $this->assertCount(1, $this->firstPurchases($plan)[(string) $this->sponsor->id], 'un nuovo ordine a interruttore spento non rende cliente un altro cliente');
    }

    public function test_the_product_switch_also_reaches_client_counts_and_coverage(): void
    {
        $this->setUpPlan();
        $p = $this->product('K1', '40.00', ['makes_client' => true, 'customer_count' => 3, 'customer_validity_months' => 2]);
        $this->order([$p->id => 1]);
        $plan = $this->planWith(['client_rule' => 'min_amount', 'client_min_amount' => '150']);
        $input = SnapshotBuilder::build($this->period, $plan);

        $segments = array_values($input->customerCoverage[(string) $this->sponsor->id])[0];
        $this->assertSame(3, $segments[0][2], 'il prodotto vale 3 clienti');
        $this->assertSame(2, $segments[0][1] - $segments[0][0] + 1, 'copre 2 mesi');
    }

    public function test_the_engine_rule_has_two_alternative_ways(): void
    {
        $cfg = $this->planWith(['client_rule' => 'min_amount', 'client_min_amount' => '150'])->kosmos;
        $this->assertFalse($cfg->orderQualifies(BigDecimal::of('149.99')));
        $this->assertTrue($cfg->orderQualifies(BigDecimal::of('150')));
        $this->assertTrue($cfg->orderQualifies(BigDecimal::of('10'), true), 'prodotto che rende cliente');
        $this->assertFalse($cfg->orderQualifies(BigDecimal::of('0'), true), 'un ordine stornato del tutto non conta');
    }
}
