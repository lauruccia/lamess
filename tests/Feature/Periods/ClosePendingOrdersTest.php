<?php

declare(strict_types=1);

namespace Tests\Feature\Periods;

use App\Domain\Orders\OrderService;
use App\Domain\Periods\PeriodService;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Period;
use App\Models\PvMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class ClosePendingOrdersTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;
    use ShopDb;

    private function pendingTransferOrder(): Order
    {
        $this->plan();
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        $this->shipping('0.00');
        $p = $this->product('P', '100.00');

        return app(OrderService::class)->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'transfer', $this->billing());
    }

    public function test_pending_orders_are_cancelled_automatically_when_the_month_is_closed(): void
    {
        $order = $this->pendingTransferOrder();
        $period = Period::query()->latest('id')->firstOrFail();
        $this->assertSame('pending_payment', $order->status);
        $this->assertGreaterThan(0, PvMovement::where('order_id', $order->id)->where('status', 'pending_payment')->count());

        $n = app(PeriodService::class)->cancelPendingOrders($period);

        $this->assertSame(1, $n);
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame(0, PvMovement::where('period_id', $period->id)->where('status', 'pending_payment')->count());
        $this->assertTrue(OrderEvent::where('order_id', $order->id)->where('message', 'like', '%chiusura del mese%')->exists());
    }

    public function test_paid_orders_are_left_alone(): void
    {
        $order = $this->pendingTransferOrder();
        $paid = app(OrderService::class)->markPaid($order);
        $period = Period::query()->latest('id')->firstOrFail();

        $this->assertSame(0, app(PeriodService::class)->cancelPendingOrders($period));
        $this->assertSame('paid', $paid->refresh()->status);
    }
}
