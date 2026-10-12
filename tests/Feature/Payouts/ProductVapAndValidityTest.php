<?php

declare(strict_types=1);

namespace Tests\Feature\Payouts;

use App\Domain\Engine\Calc\KosmosCalculator;
use App\Domain\Engine\Calc\PeriodCalculator;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Engine\Support\PeriodKey;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\OrderItem;
use App\Models\Period;
use App\Models\Product;
use App\Models\PvMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** V.A.P. e validità cliente decisi dal singolo prodotto (piano Incaricati). */
final class ProductVapAndValidityTest extends TestCase
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
        $data['kosmos']['client_rule'] = 'any_order';
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $this->period = $this->openPeriod(now('Europe/Rome')->format('Y-m'), $plan);
        $this->shipping();
        $this->sponsor = $this->consultant('S', null, null, now('Europe/Rome')->startOfMonth()->addMinutes(5)->format('Y-m-d H:i'));
    }

    private function plan(): PlanConfig
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous'];
        $data['kosmos']['client_rule'] = 'any_order';

        return PlanConfig::fromArray($data);
    }

    /** @param array<int, int> $lines prodotto => quantità */
    private function order(array $lines): int
    {
        $svc = app(OrderService::class);
        $order = $svc->place($this->buyer($this->sponsor), $lines, 'IT', 'transfer', $this->billing());
        $svc->markPaid($order);

        return $order->id;
    }

    public function test_a_fixed_vap_is_frozen_on_the_order_line_and_multiplied_by_the_pieces(): void
    {
        $this->setUpPlan();
        $p = $this->product('FIX', '100.00', ['vap_mode' => Product::VAP_FIXED, 'vap_value' => '40.00', 'customer_validity_months' => 3, 'customer_count' => 5]);
        $orderId = $this->order([$p->id => 2]);

        $m = PvMovement::where('order_id', $orderId)->firstOrFail();
        $this->assertSame('80.00', (string) $m->vap_amount);
        $this->assertSame(3, (int) $m->validity_months);
        $this->assertSame(5, (int) $m->client_count);

        $p->update(['vap_value' => '10.00', 'customer_validity_months' => 1]);
        $item = OrderItem::where('order_id', $orderId)->firstOrFail();
        $this->assertSame('40.0000', (string) $item->vap_value, 'la modifica del prodotto non tocca gli ordini già fatti');
        $this->assertSame('-40.00', '-'.$item->vapAmount(1), 'un reso di un pezzo storna 40 €');
    }

    public function test_a_percent_vap_applies_to_the_gross_price_and_a_product_without_vap_uses_the_plan(): void
    {
        $this->setUpPlan();
        $pct = $this->product('PCT', '100.00', ['vap_mode' => Product::VAP_PERCENT, 'vap_value' => '50']); // 122,00 € × 50% = 61,00
        $plain = $this->product('PLAIN', '100.00');
        $a = $this->order([$pct->id => 1]);
        $b = $this->order([$plain->id => 1]);

        $this->assertSame('61.00', (string) PvMovement::where('order_id', $a)->firstOrFail()->vap_amount);
        $this->assertNull(PvMovement::where('order_id', $b)->firstOrFail()->vap_amount);
        $this->assertSame(1, (int) OrderItem::where('order_id', $b)->firstOrFail()->validity_months, 'senza impostazione vale 1 mese');
    }

    public function test_a_variant_inherits_vap_and_validity_from_the_main_product(): void
    {
        $parent = $this->product('PAR', '100.00', ['vap_mode' => Product::VAP_FIXED, 'vap_value' => '25', 'customer_validity_months' => 12, 'customer_count' => 4]);
        $variant = $this->product('VAR', '100.00', ['parent_id' => $parent->id]);

        $this->assertSame([Product::VAP_FIXED, '25.0000'], $variant->vapSetting());
        $this->assertSame(12, $variant->customerValidityMonths());
        $this->assertSame(4, $variant->customerCount());
    }

    public function test_the_direct_commission_is_calculated_on_the_product_vap(): void
    {
        $this->setUpPlan();
        $fixed = $this->product('FIX', '100.00', ['vap_mode' => Product::VAP_FIXED, 'vap_value' => '40.00']);
        $this->order([$fixed->id => 1]);

        $result = PeriodCalculator::calculate(SnapshotBuilder::build($this->period, $this->plan()));
        $direct = array_values(array_filter($result->lines, fn ($l) => $l->bonus === 'direct' && $l->beneficiaryId === (string) $this->sponsor->id));
        $this->assertCount(1, $direct);
        $this->assertSame('40.00', (string) $direct[0]->base, 'base = V.A.P. del prodotto, non il 66,67% dell’importo');
    }

    public function test_the_customer_stays_valid_for_the_months_of_the_product_in_the_snapshot(): void
    {
        $this->setUpPlan();
        $p = $this->product('BOX10', '100.00', ['customer_validity_months' => 12]);
        $this->order([$p->id => 1]);

        $input = SnapshotBuilder::build($this->period, $this->plan());
        $intervals = $input->customerCoverage[(string) $this->sponsor->id];
        $this->assertCount(1, $intervals);
        $customer = array_key_first($intervals);
        [$first, $last, $clients] = $intervals[$customer][0];
        $this->assertSame(1, $clients);
        $this->assertSame(11, $last - $first, '12 mesi dal mese dell’ordine');
    }

    public function test_coverage_segments_add_up_from_the_expiry_and_split_after_a_gap(): void
    {
        $d = static fn (string $s) => new \DateTimeImmutable($s);
        $jan = KosmosCalculator::monthIndex($d('2026-01-10'));

        $this->assertSame([[$jan, $jan + 2, 1]], KosmosCalculator::coverageIntervals([['at' => $d('2026-01-10'), 'months' => 3]]));
        $this->assertSame([[$jan, $jan + 2, 1], [$jan + 3, $jan + 4, 5]], KosmosCalculator::coverageIntervals([
            ['at' => $d('2026-01-10'), 'months' => 3], ['at' => $d('2026-03-05'), 'months' => 2, 'clients' => 5], // ancora valido a marzo: i 2 mesi partono dalla scadenza
        ]));
        $this->assertSame([[$jan, $jan, 1], [$jan + 5, $jan + 5, 1]], KosmosCalculator::coverageIntervals([
            ['at' => $d('2026-01-10'), 'months' => 1], ['at' => $d('2026-06-02'), 'months' => 1], // scaduto: riparte dal nuovo ordine
        ]));
    }

    public function test_a_covered_client_counts_in_later_months_without_a_new_order(): void
    {
        $this->setUpPlan();
        $plan = $this->plan();
        $key = PeriodKey::parse($this->period->code);
        $month = KosmosCalculator::monthIndex($key->start());
        $coverage = ['7' => ['c1' => [[$month - 1, $month + 1, 1]], 'c2' => [[$month - 5, $month - 3, 1]], 'self:7' => [[$month, $month, 1]], 'c3' => [[$month, $month + 11, 5]]]];

        $counts = KosmosCalculator::clientCounts($plan, $key, [], [], [], $coverage);

        $this->assertSame(7, $counts['7'], 'c1 vale 1, c2 è scaduto, l’acquisto proprio vale 1 (politica attiva), c3 è un prodotto da 5 clienti');
    }
}
