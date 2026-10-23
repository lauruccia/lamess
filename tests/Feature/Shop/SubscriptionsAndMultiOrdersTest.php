<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Network\NetworkService;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PaymentResult;
use App\Domain\Subscriptions\SubscriptionService;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Models\AuditEntry;
use App\Models\Consultant;
use App\Models\Order;
use App\Models\PvMovement;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class SubscriptionsAndMultiOrdersTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private bool $cardWorks = true;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->plan();
        $this->shipping('10.00');
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        $this->cardWorks = true;
        $test = $this;
        $this->app->instance(PaymentProvider::class, new class($test) implements PaymentProvider
        {
            public function __construct(private readonly SubscriptionsAndMultiOrdersTest $t) {}

            public function name(): string
            {
                return 'test';
            }

            public function charge(Order $order, array $details = []): PaymentResult
            {
                return $this->t->cardWorksNow() ? new PaymentResult(true, 'T-'.$order->id) : new PaymentResult(false, null, 'rifiutata');
            }
        });
    }

    public function cardWorksNow(): bool
    {
        return $this->cardWorks;
    }

    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    private function subs(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    /** Incaricato con account, due clienti assegnati e un incaricato diretto. @return array{0:Consultant,1:User,2:User,3:User,4:User} */
    private function team(): array
    {
        $me = $this->activeSponsor();
        $me->update(['user_id' => ($meUser = $this->buyer())->id]);
        $c1 = $this->fan($me)->user;
        $c2 = $this->fan($me)->user;
        $direct = app(NetworkService::class)->enroll(['code' => 'LM00002', 'name' => 'Figlio', 'referral_code' => 'REFLM00002'], $me, $this->at('2026-02-01 10:00'));
        $direct->update(['user_id' => ($dUser = $this->buyer())->id]);

        return [$me, $meUser, $c1, $c2, $dUser];
    }

    public function test_g38_multi_recipient_order_has_one_shipping_and_points_per_recipient(): void
    {
        [$me, $meUser, $c1, $c2, $dUser] = $this->team();
        $p = $this->product('A', '123.00');

        $o = $this->orders()->place($meUser, [$p->id => 1], 'IT', 'card', $this->billing(), null, null, [$c1->id => [$p->id => 2], $c2->id => [$p->id => 1], $dUser->id => [$p->id => 1]]);

        $this->assertSame('10.00', $o->shipping_net, 'una sola spedizione per tutto l\'ordine');
        $this->assertSame(5, $o->items->where('is_gift', false)->sum('qty'));
        $this->assertTrue($this->orders()->payByCard($o));

        $byUser = $o->items->groupBy('recipient_user_id')->map(fn ($g) => $g->sum('qty'));
        $this->assertSame(2, $byUser[$c1->id]);
        $this->assertSame(1, $byUser[$c2->id]);
        $this->assertSame(1, $byUser[$dUser->id]);
        $this->assertSame('500.00', $o->fresh()->pv_total);
        $this->assertSame(4, PvMovement::count(), 'un movimento punti per ciascun destinatario più quello dell\'acquirente');
        $this->assertSame(4, PvMovement::where('status', 'confirmed')->count(), 'tutti i punti sono confermati');
    }

    public function test_g38_cannot_order_for_someone_outside_the_network(): void
    {
        [, $meUser] = $this->team();
        $stranger = $this->fan(null)->user;
        $p = $this->product('A', '10.00');

        $this->expectException(OrderException::class);
        $this->orders()->place($meUser, [$p->id => 1], 'IT', 'card', $this->billing(), null, null, [$stranger->id => [$p->id => 1]]);
    }

    public function test_a_plain_customer_cannot_order_for_others(): void
    {
        $other = $this->fan()->user;
        $buyer = $this->fan()->user;
        $p = $this->product('A', '10.00');

        $this->expectException(OrderException::class);
        $this->orders()->place($buyer, [$p->id => 1], 'IT', 'card', $this->billing(), null, null, [$other->id => [$p->id => 1]]);
    }

    public function test_g45_single_order_pays_shipping(): void
    {
        $p = $this->product('A', '123.00');
        $o = $this->orders()->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing());
        $this->assertSame('10.00', $o->shipping_net);
        $this->assertFalse((bool) $o->shipping_waived);
    }

    public function test_g39_twelve_month_subscription_ships_free_every_month(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $start = now('Europe/Rome')->startOfMonth()->addDays(9)->setTime(10, 0);
        $this->travelTo($start);

        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);
        $this->assertTrue((bool) $sub->free_shipping);
        $this->assertSame(1, $sub->cycles_done);

        foreach ([1, 2] as $m) {
            $this->travelTo($start->copy()->addMonthsNoOverflow($m));
            $this->assertSame(['paid' => 1, 'failed' => 0, 'paused' => 0], $this->subs()->runDue());
        }
        $this->assertSame(0, $this->subs()->runDue()['paid'], 'nessun doppio addebito nello stesso mese');

        $orders = Order::where('subscription_id', $sub->id)->get();
        $this->assertCount(3, $orders);
        $this->assertSame(['0.00'], $orders->pluck('shipping_net')->unique()->values()->all());
        $this->assertTrue($orders->every(fn ($o) => $o->status === 'paid' && $o->shipping_waived));
        $this->assertSame(3, $sub->refresh()->cycles_done);
        $this->assertEquals($start->copy()->addMonthsNoOverflow(3)->toDateString(), $sub->next_run_at->setTimezone('Europe/Rome')->toDateString());
    }

    public function test_subscription_without_commitment_has_free_shipping_by_default(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);

        $this->assertTrue((bool) $sub->free_shipping);
        $this->assertSame('0.00', Order::where('subscription_id', $sub->id)->firstOrFail()->shipping_net);
    }

    public function test_skip_next_month_shifts_the_calendar_without_an_order(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $svc = $this->subs();
        $sub = $svc->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $next = $sub->next_run_at;

        $sub = $svc->skipNext($sub);
        $this->assertSame(1, $sub->skip_months);
        $this->assertEquals($next->addMonthNoOverflow()->toDateString(), $sub->next_run_at->toDateString());
        $this->assertSame(1, Order::where('subscription_id', $sub->id)->count()); // nessun ordine nel mese saltato
    }

    public function test_skip_is_limited_ahead_and_only_for_active(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $svc = $this->subs();
        $sub = $svc->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $svc->skipNext($sub);
        $svc->skipNext($sub->refresh());
        $this->expectException(OrderException::class); // il terzo supera l'orizzonte di 3 mesi
        $svc->skipNext($sub->refresh());
    }

    public function test_shipping_change_applies_to_next_orders_only_and_keeps_billing(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $svc = $this->subs();
        $sub = $svc->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $first = Order::where('subscription_id', $sub->id)->firstOrFail();
        $this->assertSame('Via Roma 1', $first->billing['shipping']['address']); // senza scelta: come la fatturazione

        $sub = $svc->updateShipping($sub, ['address' => 'Via Nuova 5', 'city' => 'Messina', 'zip' => '98100', 'province' => 'ME', 'label' => 'Mare']);
        $this->assertSame('Via Nuova 5', $sub->billing['shipping']['address']);
        $this->assertSame('Via Roma 1', $sub->billing['address']); // fatturazione intatta

        $svc->runCycle($sub, CarbonImmutable::now());
        $second = Order::where('subscription_id', $sub->id)->latest('id')->firstOrFail();
        $this->assertSame('Via Nuova 5', $second->billing['shipping']['address']);
        $this->assertSame('Via Roma 1', $second->billing['address']);
        $this->assertSame('Via Roma 1', $first->refresh()->billing['shipping']['address']);
    }

    public function test_only_one_open_subscription_per_user(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);

        $this->expectException(OrderException::class);
        $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);
    }

    public function test_failed_charge_retries_then_pauses_and_can_be_resumed(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $this->cardWorks = false;
        $this->travelTo(now('Europe/Rome')->startOfMonth()->addDays(9)->setTime(10, 0));

        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);
        $this->assertSame('active', $sub->status);
        $this->assertSame(1, $sub->retry_count);
        $this->assertSame(0, $sub->cycles_done);
        $this->assertSame(0, PvMovement::where('status', 'pending_payment')->count(), 'l\'ordine non pagato viene annullato e non lascia punti in sospeso');

        foreach ([1, 3, 5] as $days) {
            $this->travelTo(now()->addDays($days));
            $paused = $this->subs()->runDue()['paused'];
        }
        $this->assertSame(1, $paused);
        $this->assertSame('paused', $sub->refresh()->status);

        $this->cardWorks = true;
        $this->subs()->resume($sub);
        $this->assertSame('active', $sub->refresh()->status);
        $this->assertSame(1, $sub->cycles_done);
    }

    public function test_pause_and_resume_in_the_same_month_does_not_charge_twice(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);
        $this->assertSame(1, $sub->cycles_done);

        $this->subs()->pause($sub->refresh());
        $this->subs()->resume($sub->refresh());

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame(1, $sub->cycles_done);
        $this->assertSame(1, Order::where('subscription_id', $sub->id)->count(), 'il mese era già pagato: nessun secondo ordine');
        $this->assertTrue($sub->next_run_at->equalTo(CarbonImmutable::instance($sub->started_at)->addMonthNoOverflow()));
    }

    public function test_resume_after_a_long_pause_charges_once_and_does_not_catch_up(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);
        $this->subs()->pause($sub->refresh());

        $this->travelTo(CarbonImmutable::instance($sub->started_at)->addMonthsNoOverflow(3)->addDays(5));
        $this->subs()->resume($sub->refresh());

        $sub->refresh();
        $this->assertSame(2, $sub->cycles_done);
        $this->assertSame(2, Order::where('subscription_id', $sub->id)->count());
        $this->assertTrue($sub->next_run_at->greaterThan(now()), 'la prossima scadenza è futura');
        $this->assertTrue($sub->next_run_at->lessThanOrEqualTo(now()->addMonthNoOverflow()->addDay()));
        $this->assertSame(0, $this->subs()->runDue()['paid'], 'i mesi di pausa non si recuperano il giorno dopo');
    }

    public function test_early_cancellation_is_flagged_and_audited(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);

        $this->subs()->cancel($sub);

        $this->assertTrue((bool) $sub->refresh()->early_cancel);
        $this->assertNull($sub->next_run_at);
        $this->assertTrue(AuditEntry::where('action', 'subscription.cancelled')->exists());
        $this->expectException(OrderException::class);
        $this->subs()->cancel($sub);
    }

    public function test_customer_area_pages_and_actions(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);

        $this->actingAs($u)->get('/area/abbonamenti')->assertOk()->assertSee('Spedizione gratuita')->assertSee('Prodotto A');
        $this->actingAs($u)->post("/area/abbonamenti/{$sub->id}/pausa")->assertRedirect('/area/abbonamenti');
        $this->assertSame('paused', $sub->refresh()->status);

        $this->actingAs($this->buyer())->post("/area/abbonamenti/{$sub->id}/annulla")->assertNotFound();
    }

    public function test_cart_for_others_flow_and_checkout_subscription(): void
    {
        [, $meUser, $c1] = $this->team();
        $p = $this->product('A', '123.00');

        $this->actingAs($meUser)->post('/area/carrello/per-altri', ['recipient_id' => $c1->id, 'product_id' => $p->id, 'qty' => 2])->assertRedirect('/carrello');
        $this->actingAs($meUser)->get('/carrello')->assertOk()->assertSee('Per '.$c1->name);

        $stranger = $this->fan()->user;
        $this->actingAs($meUser)->post('/area/carrello/per-altri', ['recipient_id' => $stranger->id, 'product_id' => $p->id, 'qty' => 1])->assertForbidden();

        $this->actingAs($meUser)->post('/area/checkout', $this->billing() + ['payment_method' => 'transfer', 'subscribe' => 'monthly', 'privacy' => '1'])
            ->assertSessionHasErrors('order');
        $this->assertSame(0, Subscription::count());
    }

    public function test_admin_can_list_pause_resume_and_cancel_subscriptions(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 12);
        $this->actingAs(User::factory()->role('super_admin')->create());

        $this->get('/admin/subscriptions')->assertOk();
        $page = Livewire::test(ListSubscriptions::class);
        $page->callTableAction('pause', $sub)->assertNotified();
        $this->assertSame('paused', $sub->refresh()->status);
        $page->callTableAction('cancel', $sub, ['reason' => 'richiesta telefonica'])->assertNotified();
        $this->assertSame('cancelled', $sub->refresh()->status);
    }
}
