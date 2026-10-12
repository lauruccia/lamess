<?php

declare(strict_types=1);

namespace Tests\Feature\Periods;

use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Orders\OrderService;
use App\Domain\Periods\PeriodService;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\CommissionLine;
use App\Models\CompPlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Il piano Incaricati legge i clienti dagli ordini: identità del cliente e data del primo acquisto. */
final class KosmosSnapshotTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    public function test_snapshot_carries_customer_identity_and_the_incaricati_lines_are_persisted(): void
    {
        $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true);
        $data['policies'] = ['activity_month_rule' => 'current_or_previous'];
        $plan = CompPlanVersion::createFromArray('Incaricati', $data, CompPlanVersion::ACTIVE);
        $period = $this->openPeriod(now('Europe/Rome')->format('Y-m'), $plan);
        $this->shipping();
        $product = $this->product('A', '123.00');
        $sponsor = $this->activeSponsor();
        $svc = app(OrderService::class);

        $buyers = [$this->buyer($sponsor), $this->buyer($sponsor)];
        foreach ([$buyers[0], $buyers[1], $buyers[0]] as $u) {
            $svc->markPaid($svc->place($u, [$product->id => 1], 'IT', 'transfer', $this->billing()));
        }

        $input = SnapshotBuilder::build($period);
        $ids = array_unique(array_map(fn ($m) => $m->customerId, $input->movements));
        sort($ids);
        $this->assertSame([(string) $buyers[0]->id, (string) $buyers[1]->id], $ids);
        $this->assertCount(2, $input->customerFirstPurchase[(string) $sponsor->id]);

        $run = app(PeriodService::class)->calculate($period);
        $direct = CommissionLine::where('run_id', $run->id)->where('bonus_type', 'direct')->get();
        $this->assertCount(3, $direct);
        $this->assertSame('10.00', number_format((float) $direct->first()->amount, 2, '.', ''), '2 clienti distinti: scaglione del 10% su 100');
    }
}
