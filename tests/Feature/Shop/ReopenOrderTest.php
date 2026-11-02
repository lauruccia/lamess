<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\PvMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Il super admin può riaprire un ordine annullato: torna in attesa di pagamento con giacenze e punti ripristinati. */
final class ReopenOrderTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;
    use ShopDb;

    private function cancelledOrder(int $stock = 5): Order
    {
        $this->plan();
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        $this->shipping('0.00');
        $p = $this->product('P', '100.00', ['stock_qty' => $stock]);
        $order = app(OrderService::class)->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'transfer', $this->billing());

        return app(OrderService::class)->cancel($order);
    }

    private function stock(): int
    {
        return (int) Product::where('sku', 'P')->value('stock_qty');
    }

    public function test_reopening_restores_stock_points_and_status(): void
    {
        $order = $this->cancelledOrder();
        $this->assertSame(5, $this->stock());
        $this->assertSame('cancelled', $order->status);

        $reopened = app(OrderService::class)->reopen($order, 'Il cliente ha pagato con bonifico');

        $this->assertSame('pending_payment', $reopened->status);
        $this->assertSame(4, $this->stock());
        $this->assertGreaterThan(0, PvMovement::where('order_id', $order->id)->where('status', 'pending_payment')->count());
        $this->assertSame(0, PvMovement::where('order_id', $order->id)->where('status', 'cancelled')->count());
        $this->assertTrue(OrderEvent::where('order_id', $order->id)->where('type', 'reopened')->exists());

        // ed ora si può confermare il pagamento
        $this->assertSame('paid', app(OrderService::class)->markPaid($reopened)->status);
    }

    public function test_reopening_fails_without_stock_and_leaves_the_order_cancelled(): void
    {
        $order = $this->cancelledOrder();
        Product::where('sku', 'P')->update(['stock_qty' => 0]);

        try {
            app(OrderService::class)->reopen($order, 'Prova senza giacenza');
            $this->fail('La riapertura senza merce disponibile doveva fallire.');
        } catch (OrderException $e) {
            $this->assertStringContainsString('esaurito', $e->getMessage());
        }

        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame(0, PvMovement::where('order_id', $order->id)->where('status', 'pending_payment')->count());
        $this->assertSame(0, $this->stock());
    }

    public function test_an_order_with_refunded_kmoney_or_not_cancelled_cannot_be_reopened(): void
    {
        $order = $this->cancelledOrder();
        $order->payments()->create(['provider' => 'kmoney', 'method' => 'kmoney', 'amount' => '10.00', 'status' => 'refunded']);

        $this->expectException(OrderException::class);
        app(OrderService::class)->reopen($order, 'Prova con KMoney rimborsato');
    }

    public function test_a_reason_is_required(): void
    {
        $order = $this->cancelledOrder();

        $this->expectException(OrderException::class);
        app(OrderService::class)->reopen($order, '  ');
    }

    public function test_only_the_super_admin_sees_and_runs_the_action(): void
    {
        $order = $this->cancelledOrder();

        $this->actingAs(User::factory()->role('admin_finance')->create());
        Livewire::test(ListOrders::class)->assertTableActionHidden('reopen', $order);

        $this->actingAs(User::factory()->role('super_admin')->create());
        Livewire::test(ListOrders::class)
            ->assertTableActionVisible('reopen', $order)
            ->callTableAction('reopen', $order, ['reason' => 'Bonifico arrivato in ritardo'])
            ->assertNotified();
        $this->assertSame('pending_payment', $order->refresh()->status);
    }
}
