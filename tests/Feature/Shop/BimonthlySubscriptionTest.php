<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\OrderException;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PaymentResult;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Abbonamento bimestrale: un ordine ogni 2 mesi, spedizione sempre gratuita. */
final class BimonthlySubscriptionTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->plan();
        $this->shipping('10.00');
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        $this->app->instance(PaymentProvider::class, new class implements PaymentProvider
        {
            public function name(): string
            {
                return 'test';
            }

            public function charge(Order $order, array $details = []): PaymentResult
            {
                return new PaymentResult(true, 'T-'.$order->id);
            }
        });
    }

    public function test_bimonthly_orders_every_two_months_with_free_shipping(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $start = now('Europe/Rome')->startOfMonth()->addDays(9)->setTime(10, 0);
        $this->travelTo($start);
        $svc = app(SubscriptionService::class);

        $sub = $svc->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12, [], null, 'card', 2);
        $this->assertSame(2, $sub->interval_months);
        $this->assertSame(1, $sub->cycles_done);
        $this->assertEquals($start->copy()->addMonthsNoOverflow(2)->toDateString(), $sub->next_run_at->setTimezone('Europe/Rome')->toDateString());

        $this->travelTo($start->copy()->addMonth());
        $this->assertSame(0, $svc->runDue()['paid'], 'dopo un solo mese non parte nulla');

        $this->travelTo($start->copy()->addMonthsNoOverflow(2));
        $this->assertSame(1, $svc->runDue()['paid']);

        $orders = Order::where('subscription_id', $sub->id)->get();
        $this->assertCount(2, $orders);
        $this->assertSame(['0.00'], $orders->pluck('shipping_net')->unique()->values()->all());
        $this->assertEquals($start->copy()->addMonthsNoOverflow(4)->toDateString(), $sub->refresh()->next_run_at->setTimezone('Europe/Rome')->toDateString());
        $this->assertSame(6, $sub->commitmentCycles(), '12 mesi = 6 ordini');
        $this->assertSame('ogni 2 mesi', $sub->cadenceLabel());
    }

    public function test_skip_moves_the_next_order_by_one_cycle(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $svc = app(SubscriptionService::class);
        $sub = $svc->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'card', 2);
        $next = $sub->next_run_at;

        $sub = $svc->skipNext($sub);

        $this->assertEquals($next->addMonthsNoOverflow(2)->toDateString(), $sub->next_run_at->toDateString());
    }

    public function test_only_one_or_two_months_are_allowed_and_default_is_monthly(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $svc = app(SubscriptionService::class);

        try {
            $svc->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'card', 3);
            $this->fail('cadenza non valida accettata');
        } catch (OrderException) {
            $this->assertSame(0, Subscription::count());
        }
        $this->assertSame(1, $svc->subscribe($u, [$p->id => 1], 'IT', $this->billing())->interval_months);
    }

    public function test_checkout_accepts_the_bimonthly_choice(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])
            ->post('/area/checkout', $this->billing() + ['payment_method' => 'card', 'privacy' => '1', 'subscribe' => 'bimonthly'])
            ->assertRedirect('/area/abbonamenti');

        $this->assertSame(2, Subscription::firstOrFail()->interval_months);
        $this->actingAs($u)->get('/area/abbonamenti')->assertOk()->assertSee('ogni 2 mesi');
    }
}
