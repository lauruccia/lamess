<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PaymentResult;
use App\Domain\Orders\RefundsCardPayments;
use App\Domain\Orders\RefundService;
use App\Domain\Orders\ReturnService;
use App\Filament\Pages\ShopSettingsPage;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\User;
use App\Notifications\OrderShipped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Gestione ordini da e-commerce completo: evasione, resi con rimborso in denaro, giacenza, impostazioni dei pagamenti. */
final class OrderManagementTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function paidOrder(string $method = 'card', int $qty = 2, array $productOver = []): Order
    {
        Notification::fake();
        $this->plan();
        $p = Product::first() ?? $this->product('A', '123.00', $productOver);
        $orders = app(OrderService::class);
        $order = $orders->place($this->buyer($this->activeSponsor()), [$p->id => $qty], 'IT', $method, $this->billing());
        match ($method) {
            'card' => $orders->payByCard($order),
            'kmoney' => $orders->payByKMoney($order),
            default => $orders->markPaid($order),
        };

        return $order->fresh();
    }

    /** @return array<int,int> */
    private function qty(Order $order, int $n): array
    {
        return [$order->items->first()->id => $n];
    }

    public function test_fulfilment_flow_logs_history_and_notifies_the_customer_on_shipping(): void
    {
        $order = $this->paidOrder();
        $svc = app(OrderService::class);

        $this->assertSame('processing', $svc->startProcessing($order)->status);
        $shipped = $svc->ship($order->fresh(), 'TRK1', 'BRT');
        $this->assertSame(['shipped', 'BRT', 'TRK1'], [$shipped->status, $shipped->carrier, $shipped->tracking_code]);
        Notification::assertSentTo($order->buyer, OrderShipped::class);
        $this->assertSame('delivered', $svc->markDelivered($order->fresh())->status);
        $this->assertNotNull($order->fresh()->delivered_at);

        $types = $order->events()->pluck('type')->all();
        foreach (['created', 'paid', 'processing', 'shipped', 'delivered'] as $t) {
            $this->assertContains($t, $types);
        }
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $order = $this->paidOrder();
        $svc = app(OrderService::class);
        try {
            $svc->markDelivered($order);
            $this->fail('un ordine non spedito non si consegna');
        } catch (OrderException) {
            $this->assertSame('paid', $order->fresh()->status);
        }
        $svc->ship($order, null, null);
        $this->expectException(OrderException::class);
        $svc->startProcessing($order->fresh());
    }

    public function test_internal_notes_are_kept_in_the_history_and_never_empty(): void
    {
        $order = $this->paidOrder();
        $svc = app(OrderService::class);
        $svc->addNote($order, 'Cliente chiede consegna al mattino');
        $this->assertTrue($order->events()->where('type', 'note')->where('internal', true)->exists());
        $this->expectException(OrderException::class);
        $svc->addNote($order, '   ');
    }

    public function test_return_with_restock_sends_the_money_back_to_the_card(): void
    {
        $order = $this->paidOrder('card', 2, ['stock_qty' => 10]);
        $this->assertSame(8, Product::first()->stock_qty);

        $note = app(ReturnService::class)->refund($order, $this->qty($order, 1), 'difetto', restock: true);
        $this->assertSame(9, Product::first()->stock_qty, 'la merce resa torna in giacenza');

        $refunds = app(RefundService::class)->payBack($order->fresh(), $note);
        $this->assertCount(1, $refunds);
        $this->assertSame('card', $refunds[0]->method);
        $this->assertSame(Refund::DONE, $refunds[0]->status);
        $this->assertSame((string) $note->total, (string) $refunds[0]->amount);
        $this->assertStringStartsWith('FAKE-RF-', (string) $refunds[0]->provider_ref);
        $this->assertSame('partially_refunded', $order->fresh()->status);
        $this->assertContains('refund_done', $order->events()->pluck('type')->all());
    }

    public function test_return_without_restock_leaves_the_stock_alone(): void
    {
        $order = $this->paidOrder('card', 2, ['stock_qty' => 10]);
        app(ReturnService::class)->refund($order, $this->qty($order, 1), 'cambio idea');
        $this->assertSame(8, Product::first()->stock_qty);
    }

    public function test_full_return_refunds_exactly_what_was_paid(): void
    {
        $order = $this->paidOrder('card', 2);
        $note = app(ReturnService::class)->refund($order, $this->qty($order, 2), 'reso totale');
        app(RefundService::class)->payBack($order->fresh(), $note);

        $this->assertSame('refunded', $order->fresh()->status);
        $this->assertSame((string) $order->total, number_format((float) Refund::sum('amount'), 2, '.', ''));
        // un secondo rimborso sullo stesso pagamento non esce: non c'è più nulla da restituire
        $this->assertSame([], app(RefundService::class)->payBack($order->fresh(), CreditNote::first()));
    }

    public function test_bank_transfer_refund_is_manual_until_marked_done(): void
    {
        $order = $this->paidOrder('transfer');
        $note = app(ReturnService::class)->refund($order, $this->qty($order, 1), 'reso');
        [$refund] = app(RefundService::class)->payBack($order->fresh(), $note);

        $this->assertSame([Refund::PENDING, 'transfer'], [$refund->status, $refund->method]);
        $done = app(RefundService::class)->markDone($refund, 'CRO 123');
        $this->assertSame(Refund::DONE, $done->status);
        $this->assertNotNull($done->refunded_at);
        $this->expectException(OrderException::class);
        app(RefundService::class)->markDone($done);
    }

    public function test_kmoney_order_is_refunded_to_kmoney(): void
    {
        $order = $this->paidOrder('kmoney');
        $note = app(ReturnService::class)->refund($order, $this->qty($order, 1), 'reso');
        [$refund] = app(RefundService::class)->payBack($order->fresh(), $note);

        $this->assertSame(['kmoney', Refund::DONE], [$refund->method, $refund->status]);
    }

    public function test_failed_card_refund_can_be_retried(): void
    {
        $provider = new class implements PaymentProvider, RefundsCardPayments
        {
            public int $calls = 0;

            public function name(): string
            {
                return 'test';
            }

            public function charge(Order $order, array $details = []): PaymentResult
            {
                return new PaymentResult(true, 'CH-1');
            }

            public function refundPayment(Payment $payment, string $amount, string $idempotencyKey): PaymentResult
            {
                return ++$this->calls === 1 ? new PaymentResult(false, null, 'Stripe non risponde') : new PaymentResult(true, 're_ok');
            }
        };
        $this->app->instance(PaymentProvider::class, $provider);

        $order = $this->paidOrder('card');
        $note = app(ReturnService::class)->refund($order, $this->qty($order, 1), 'reso');
        [$refund] = app(RefundService::class)->payBack($order->fresh(), $note);
        $this->assertSame(Refund::FAILED, $refund->status);
        $this->assertSame('Stripe non risponde', $refund->error);

        $retried = app(RefundService::class)->retry($refund);
        $this->assertSame([Refund::DONE, 're_ok'], [$retried->status, $retried->provider_ref]);
        $this->assertSame(2, $provider->calls);
    }

    public function test_disabled_payment_method_is_hidden_and_refused_at_checkout(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $user = $this->buyer($this->activeSponsor());
        config(['lamess.payments.transfer' => false]);

        $this->actingAs($user)->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        $this->get('/area/checkout/pagamento')->assertOk()->assertSee('Carta di credito')->assertDontSee('Bonifico bancario');
        $this->post('/area/checkout', [
            'type' => 'private', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'phone' => '333 1234567',
            'address' => 'Via Roma 1', 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC',
            'payment_method' => 'transfer', 'privacy' => '1', 'subscribe' => 'none',
        ])->assertSessionHasErrors('order');
        $this->assertSame(0, Order::count());
    }

    public function test_customer_can_cancel_an_unpaid_bank_transfer_order_only(): void
    {
        Notification::fake();
        $this->plan();
        $p = $this->product('A', '100.00');
        $user = $this->buyer($this->activeSponsor());
        $transfer = app(OrderService::class)->place($user, [$p->id => 1], 'IT', 'transfer', $this->billing());
        $card = app(OrderService::class)->place($user, [$p->id => 1], 'IT', 'card', $this->billing());

        $this->actingAs($user)->post("/area/ordini/{$card->id}/annulla")->assertNotFound();
        $this->post("/area/ordini/{$transfer->id}/annulla")->assertRedirect("/area/ordini/{$transfer->id}");
        $this->assertSame('cancelled', $transfer->fresh()->status);
        $this->assertSame('pending_payment', $card->fresh()->status);

        $other = $this->buyer();
        $this->actingAs($other)->post("/area/ordini/{$card->id}/annulla")->assertNotFound();
    }

    public function test_customer_sees_carrier_delivery_and_refunds_on_the_order_page(): void
    {
        $order = $this->paidOrder('card');
        $svc = app(OrderService::class);
        $svc->ship($order, 'TRK9', 'GLS');
        $svc->markDelivered($order->fresh());
        $note = app(ReturnService::class)->refund($order->fresh(), $this->qty($order, 1), 'reso');
        app(RefundService::class)->payBack($order->fresh(), $note);

        $this->actingAs($order->buyer)->get("/area/ordini/{$order->id}")->assertOk()
            ->assertSee('GLS')->assertSee('TRK9')->assertSee('Consegnato il')->assertSee('Rimborsi')->assertSee('rimborsato');
    }

    public function test_admin_manages_the_order_from_the_panel(): void
    {
        $order = $this->paidOrder('card', 2, ['stock_qty' => 10]);
        $this->actingAs(User::factory()->role('admin_finance')->create());

        $this->get('/admin/orders/'.$order->id)->assertOk()->assertSee($order->number)->assertSee('Cronologia');

        Livewire::test(ListOrders::class)->callTableAction('processing', $order)->assertNotified();
        Livewire::test(ListOrders::class)->callTableAction('ship', $order->fresh(), ['carrier' => 'BRT', 'tracking' => 'T1'])->assertNotified();
        Livewire::test(ListOrders::class)->callTableAction('deliver', $order->fresh())->assertNotified();
        $this->assertSame('delivered', $order->fresh()->status);

        Livewire::test(ListOrders::class)->callTableAction('refund', $order->fresh(), [
            'items' => [['id' => $order->items->first()->id, 'qty' => 1]], 'reason' => 'difetto', 'restock' => true, 'money' => true,
        ])->assertNotified();
        $this->assertSame(Refund::DONE, Refund::firstOrFail()->status);
        $this->assertSame(9, Product::first()->stock_qty);

        Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('note', ['note' => 'Reso concordato al telefono'])->assertNotified();
        $this->assertTrue($order->events()->where('type', 'note')->exists());
    }

    public function test_pending_refund_is_marked_done_from_the_panel(): void
    {
        $order = $this->paidOrder('transfer');
        $this->actingAs(User::factory()->role('admin_finance')->create());
        Livewire::test(ListOrders::class)->callTableAction('refund', $order, [
            'items' => [['id' => $order->items->first()->id, 'qty' => 1]], 'reason' => 'reso', 'restock' => false, 'money' => true,
        ])->assertNotified();
        $refund = Refund::firstOrFail();
        $this->assertSame(Refund::PENDING, $refund->status);

        Livewire::test(ListOrders::class)->callTableAction('refundPaid', $order->fresh(), ['refund_id' => $refund->id, 'reference' => 'CRO 9'])->assertNotified();
        $this->assertSame(Refund::DONE, $refund->fresh()->status);
    }

    public function test_orders_export_downloads_a_csv(): void
    {
        $order = $this->paidOrder();
        $this->actingAs(User::factory()->role('admin_finance')->create());
        Livewire::test(ListOrders::class)->callAction('export')->assertFileDownloaded();
        $this->assertNotNull($order);
    }

    public function test_shop_settings_page_is_for_catalog_managers_and_changes_payment_methods(): void
    {
        $this->actingAs(User::factory()->role('support')->create());
        $this->get('/admin/negozio-pagamenti')->assertForbidden();

        $this->actingAs(User::factory()->role('admin_finance')->create());
        $this->get('/admin/negozio-pagamenti')->assertOk()->assertSee('Bonifico bancario');
        Livewire::test(ShopSettingsPage::class)->fillForm(['payment_transfer_enabled' => false, 'bank_transfer_instructions' => 'IBAN IT00 TEST'])->call('save');
        $this->assertFalse(config('lamess.payments.transfer'));
        $this->assertSame('IBAN IT00 TEST', config('lamess.bank_transfer_instructions'));

        $this->actingAs(User::factory()->role('super_admin')->create());
        $this->get('/admin/negozio-pagamenti')->assertOk();
    }

    public function test_return_can_refund_shipping_once(): void
    {
        $this->shipping('5.00');
        $order = $this->paidOrder('card', 1);
        $this->assertGreaterThan(0, (float) $order->shipping_net);
        $item = $order->items->first();

        $note = app(ReturnService::class)->refund($order, [$item->id => 1], 'Reso con spedizione', false, true);

        $this->assertEqualsWithDelta(5.0, (float) $note->shipping_net, 0.001);
        $this->assertEqualsWithDelta((float) $order->subtotal_net + 5.0, (float) $note->net, 0.01);
        $this->assertEqualsWithDelta(5.0, (float) $order->fresh()->shipping_refunded_net, 0.001);
        $this->assertTrue($note->lines()->where('description', 'Spese di spedizione')->exists());

        $this->expectException(OrderException::class);
        app(ReturnService::class)->refund($order->fresh(), [], 'Ancora spedizione', false, true);
    }

    public function test_customer_return_request_is_approved_by_admin(): void
    {
        $order = $this->paidOrder('card', 2);
        $item = $order->items->first();
        app(OrderService::class)->ship($order, 'T9', 'BRT');
        app(OrderService::class)->markDelivered($order->fresh());
        $order = $order->fresh();

        $this->actingAs($order->buyer)->get("/area/ordini/{$order->id}")->assertOk()->assertSee('Richiedi il reso');
        $this->actingAs($order->buyer)->post("/area/ordini/{$order->id}/reso", ['qty' => [$item->id => 1], 'reason' => 'Non è come descritto'])
            ->assertRedirect("/area/ordini/{$order->id}");
        $this->assertSame(1, $order->returnRequests()->where('status', 'requested')->count());
        // una sola richiesta aperta alla volta
        $this->actingAs($order->buyer)->post("/area/ordini/{$order->id}/reso", ['qty' => [$item->id => 1], 'reason' => 'Bis'])->assertSessionHasErrors('return');

        $this->actingAs(User::factory()->role('admin_finance')->create());
        Livewire::test(ListOrders::class)->callTableAction('approveReturn', $order->fresh(), ['restock' => true, 'shipping' => false, 'money' => true, 'note' => 'Ok'])->assertNotified();

        $rq = $order->returnRequests()->first();
        $this->assertSame('approved', $rq->status);
        $this->assertNotNull($rq->credit_note_id);
        $this->assertSame(1, (int) $item->fresh()->qty_refunded);
        $this->assertSame('partially_refunded', $order->fresh()->status);
    }

    public function test_return_request_can_be_rejected_and_is_blocked_after_deadline(): void
    {
        $order = $this->paidOrder('card', 1);
        $item = $order->items->first();
        app(OrderService::class)->ship($order, 'T9', 'BRT');
        $order = $order->fresh();
        $this->actingAs($order->buyer)->post("/area/ordini/{$order->id}/reso", ['qty' => [$item->id => 1], 'reason' => 'Difetto']);

        $this->actingAs(User::factory()->role('admin_finance')->create());
        Livewire::test(ListOrders::class)->callTableAction('rejectReturn', $order, ['note' => 'Fuori garanzia'])->assertNotified();
        $this->assertSame('rejected', $order->returnRequests()->first()->status);
        $this->actingAs($order->buyer)->get("/area/ordini/{$order->id}")->assertSee('Fuori garanzia');

        $order->update(['shipped_at' => now()->subDays(40)]);
        $this->actingAs($order->buyer)->post("/area/ordini/{$order->id}/reso", ['qty' => [$item->id => 1], 'reason' => 'Tardi'])->assertSessionHasErrors('return');
    }

    public function test_admin_creates_an_order_by_hand(): void
    {
        Notification::fake();
        $this->plan();
        $this->shipping('5.00');
        $p = $this->product('A', '100.00');
        $customer = $this->buyer($this->activeSponsor());
        $this->actingAs(User::factory()->role('admin_finance')->create());

        $lw = Livewire::test(ListOrders::class)->callAction('createOrder', [
            'user_id' => $customer->id, 'lines' => [['product_id' => $p->id, 'qty' => 2]], 'country' => 'IT', 'method' => 'transfer', 'paid' => true,
            'type' => 'private', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U',
            'address' => 'Via Roma 1', 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC',
        ]);
        $lw->assertHasNoActionErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame($customer->id, $order->buyer_user_id);
        $this->assertSame('paid', $order->status);
        $this->assertSame(2, (int) $order->items->first()->qty);
        $this->assertNotNull($order->invoice);
        $this->assertTrue($order->events()->where('type', 'created_by_admin')->exists());
    }
}
