<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Orders\OrderService;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\Product;
use App\Models\PvMovement;
use App\Models\User;
use App\Notifications\OrderPaymentDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class ShopAdminTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function placeOrder(string $method = 'transfer'): Order
    {
        Notification::fake();
        $p = Product::first() ?? $this->product('A', '123.00');

        return app(OrderService::class)->place($this->buyer($this->activeSponsor()), [$p->id => 2], 'IT', $method, $this->billing());
    }

    public function test_pages_render_for_admin(): void
    {
        $this->plan();
        $this->actingAs(User::factory()->role()->create());
        foreach (['/products', '/products/create', '/bundle-rules', '/bundle-rules/create', '/shipping-rates', '/shipping-rates/create', '/orders', '/invoices', '/credit-notes'] as $p) {
            $this->get('/admin'.$p)->assertOk();
        }
    }

    public function test_product_can_be_created_with_automatic_pv(): void
    {
        $this->plan();
        $this->actingAs(User::factory()->role()->create());
        Livewire::test(CreateProduct::class)->fillForm(['sku' => 'N1', 'name' => 'Nuovo', 'net_price' => '246.00', 'vat_rate' => '22', 'active' => true])
            ->call('create')->assertHasNoFormErrors();
        $p = Product::where('sku', 'N1')->firstOrFail();
        $this->assertNull($p->pv);
        $this->assertSame('200.00', $p->pvUnit());
    }

    public function test_confirm_ship_and_refund_from_the_panel(): void
    {
        $this->plan();
        $order = $this->placeOrder();
        $this->actingAs(User::factory()->role('admin_finance')->create());

        Livewire::test(ListOrders::class)->callTableAction('confirm', $order)->assertNotified();
        $this->assertSame('paid', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->invoice);

        Livewire::test(ListOrders::class)->callTableAction('ship', $order->fresh(), ['tracking' => 'TRK123'])->assertNotified();
        $this->assertSame('TRK123', $order->fresh()->tracking_code);

        $item = $order->items->first();
        Livewire::test(ListOrders::class)->callTableAction('refund', $order->fresh(), ['items' => [['id' => $item->id, 'qty' => 1]], 'reason' => 'difetto'])->assertNotified();
        $this->assertSame(1, CreditNote::count());
        $this->assertSame('partially_refunded', $order->fresh()->status);
        $this->assertSame('-100.00', PvMovement::where('type', 'refund')->firstOrFail()->pv);
    }

    public function test_refund_without_reason_is_blocked(): void
    {
        $this->plan();
        $order = $this->placeOrder('card');
        app(OrderService::class)->payByCard($order);
        $this->actingAs(User::factory()->role('admin_finance')->create());
        Livewire::test(ListOrders::class)->callTableAction('refund', $order->fresh(), ['items' => [['id' => $order->items->first()->id, 'qty' => 1]], 'reason' => ''])->assertHasTableActionErrors(['reason']);
        $this->assertSame(0, CreditNote::count());
    }

    public function test_support_can_view_but_not_manage_orders_and_invoice_pdfs_are_gated(): void
    {
        $this->plan();
        $order = $this->placeOrder('card');
        app(OrderService::class)->payByCard($order);
        $inv = $order->fresh()->invoice;

        $this->actingAs(User::factory()->role('support')->create());
        Livewire::test(ListOrders::class)->assertTableActionHidden('confirm', $order)->assertTableActionHidden('refund', $order);
        $this->get("/admin-files/invoice/{$inv->id}")->assertOk();
        $this->get('/admin/products')->assertForbidden();
        auth()->logout();

        $this->actingAs(User::factory()->create())->get("/admin-files/invoice/{$inv->id}")->assertForbidden();
    }

    public function test_failed_invoice_delivery_can_be_resent_from_the_panel(): void
    {
        $this->plan();
        $order = $this->placeOrder('card');
        app(OrderService::class)->payByCard($order);
        $inv = $order->fresh()->invoice;
        $inv->update(['sink_status' => 'error', 'sink_error' => 'timeout']);
        $this->actingAs(User::factory()->role('admin_finance')->create());
        Livewire::test(ListInvoices::class)->callTableAction('resend', $inv)->assertNotified();
        $this->assertSame('sent', $inv->fresh()->sink_status);
    }

    public function test_payment_details_can_be_resent_from_the_panel(): void
    {
        $this->plan();
        config(['lamess.mail_enabled' => true, 'lamess.bank_transfer_instructions' => 'IBAN IT60X0542811101000000123456']);
        $order = $this->placeOrder();
        $this->actingAs(User::factory()->role('admin_finance')->create());

        Livewire::test(ListOrders::class)->callTableAction('sendPaymentDetails', $order)->assertNotified();

        Notification::assertSentTo($order->buyer, OrderPaymentDetails::class, fn ($n) => $n->reminder);
        $this->assertTrue($order->events()->where('type', 'payment_details_sent')->exists());
    }

    public function test_payment_details_are_not_sent_without_iban_or_with_email_off(): void
    {
        $this->plan();
        $order = $this->placeOrder();
        $this->actingAs(User::factory()->role('admin_finance')->create());

        config(['lamess.mail_enabled' => false, 'lamess.bank_transfer_instructions' => 'IBAN IT60X0542811101000000123456']);
        Livewire::test(ListOrders::class)->callTableAction('sendPaymentDetails', $order)->assertNotified();
        config(['lamess.mail_enabled' => true, 'lamess.bank_transfer_instructions' => 'IBAN da configurare — causale: numero ordine']);
        Livewire::test(ListOrders::class)->callTableAction('sendPaymentDetails', $order)->assertNotified();

        Notification::assertNothingSentTo($order->buyer);
        $this->assertFalse($order->events()->where('type', 'payment_details_sent')->exists());
    }

    public function test_payment_details_action_is_hidden_on_paid_orders(): void
    {
        $this->plan();
        $order = $this->placeOrder();
        app(OrderService::class)->markPaid($order);
        $this->actingAs(User::factory()->role('admin_finance')->create());

        Livewire::test(ListOrders::class)->assertTableActionHidden('sendPaymentDetails', $order->fresh());
    }
}
