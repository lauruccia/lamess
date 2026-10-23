<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\KMoneyApiProvider;
use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PaymentResult;
use App\Domain\Orders\StripeCheckoutProvider;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionChargeFailed;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Abbonamento pagabile con carta (Stripe: carta salvata e addebito fuori sessione) o con il saldo KMoney. */
final class SubscriptionPaymentMethodsTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private const HOOK = 'whsec_test';

    private object $km;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => self::HOOK]);
        $this->km = new class
        {
            public bool $enabled = true;

            public bool $funds = true;

            public bool $linked = true;

            public int $refunds = 0;
        };
        $km = $this->km;
        $this->app->instance(KMoneyProvider::class, new class($km) implements KMoneyProvider
        {
            public function __construct(private readonly object $s) {}

            public function name(): string
            {
                return 'kmoney-test';
            }

            public function enabled(): bool
            {
                return $this->s->enabled;
            }

            public function refund(Order $order, User $buyer): PaymentResult
            {
                $this->s->refunds++;

                return new PaymentResult(true, 'RF-'.$order->id);
            }

            public function charge(Order $order, User $buyer): PaymentResult
            {
                if (! $this->s->linked) {
                    return new PaymentResult(false, null, 'not_linked');
                }

                return $this->s->funds ? new PaymentResult(true, 'KM-'.$order->id) : new PaymentResult(false, null, 'saldo insufficiente');
            }
        });
        $this->plan();
        $this->shipping('10.00');
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
    }

    private function subs(): SubscriptionService
    {
        return app(SubscriptionService::class);
    }

    private function start(): CarbonImmutable
    {
        $t = now('Europe/Rome')->toImmutable()->startOfMonth()->addDays(9)->setTime(10, 0);
        $this->travelTo($t);

        return $t;
    }

    public function test_kmoney_subscription_charges_the_balance_every_month(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00', ['kmoney_percent' => 100]);
        $start = $this->start();

        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'kmoney');
        $this->assertSame('kmoney', $sub->payment_method);
        $this->assertSame('active', $sub->status);
        $this->assertSame(1, $sub->cycles_done, (string) $sub->last_error);

        $this->travelTo($start->addMonthNoOverflow());
        $this->assertSame(1, $this->subs()->runDue()['paid']);

        $orders = Order::where('subscription_id', $sub->id)->get();
        $this->assertCount(2, $orders);
        $this->assertSame(['kmoney'], $orders->pluck('payment_method')->unique()->values()->all());
        $this->assertTrue($orders->every(fn ($o) => $o->status === 'paid'));
        $this->assertSame(['kmoney-test'], $orders->flatMap->payments->pluck('provider')->unique()->values()->all());
    }

    public function test_kmoney_without_funds_retries_then_pauses_and_resumes_when_funds_return(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00', ['kmoney_percent' => 100]);
        $this->start();
        $this->km->funds = false;

        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'kmoney');
        $this->assertSame(1, $sub->retry_count);
        $this->assertSame('saldo insufficiente', $sub->last_error);

        // Tentativi dopo 1, 3 e 5 giorni; al 4° addebito fallito l'abbonamento va in pausa.
        foreach ([1, 3, 5] as $days) {
            $this->travelTo(now()->addDays($days));
            $paused = $this->subs()->runDue()['paused'];
        }
        $this->assertSame(1, $paused);

        $this->km->funds = true;
        $this->subs()->resume($sub->refresh());
        $this->assertSame('active', $sub->refresh()->status);
        $this->assertSame(1, $sub->cycles_done);
        $this->assertSame(0, Order::where('subscription_id', $sub->id)->where('status', 'pending_payment')->count());
    }

    public function test_kmoney_must_be_enabled_and_method_must_be_known(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $this->km->enabled = false;
        try {
            $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'kmoney');
            $this->fail('KMoney spento: l\'abbonamento non deve partire');
        } catch (OrderException $e) {
            $this->assertStringContainsString('KMoney', $e->getMessage());
        }
        $this->expectException(OrderException::class);
        $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'transfer');
    }

    public function test_checkout_accepts_kmoney_subscription_and_still_refuses_bank_transfer(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00', ['kmoney_percent' => 100]);
        $base = $this->billing() + ['subscribe' => 'monthly', 'privacy' => '1'];

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $base + ['payment_method' => 'transfer'])->assertSessionHasErrors('order');
        $this->assertSame(0, Subscription::count());

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $base + ['payment_method' => 'kmoney'])->assertRedirect('/area/abbonamenti');
        $sub = Subscription::firstOrFail();
        $this->assertSame('kmoney', $sub->payment_method);
        $this->assertSame('active', $sub->status);
        $this->actingAs($u)->get('/area/abbonamenti')->assertOk()->assertSee('Saldo KMoney');
    }

    public function test_kmoney_share_plus_card_rest_and_recovery_without_charging_kmoney_twice(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 50]);
        $start = $this->start();
        $card = new class implements PaymentProvider
        {
            public bool $works = false;

            public function name(): string
            {
                return 'card-test';
            }

            public function charge(Order $order, array $details = []): PaymentResult
            {
                return $this->works ? new PaymentResult(true, 'C-'.$order->id) : new PaymentResult(false, null, 'carta rifiutata');
            }
        };
        $this->app->instance(PaymentProvider::class, $card);

        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'kmoney');
        $this->assertSame(0, $sub->cycles_done);
        $this->assertSame('carta rifiutata', $sub->last_error);
        $order = Order::where('subscription_id', $sub->id)->firstOrFail();
        $this->assertSame('pending_payment', $order->status, 'la quota KMoney è già incassata: l\'ordine non si butta');
        $this->assertSame('card', $order->kmoney_rest_method);
        $this->assertSame(1, $order->payments()->where('method', 'kmoney')->where('status', 'paid')->count());

        $card->works = true;
        $this->travelTo($start->addDay());
        $this->assertSame(1, $this->subs()->runDue()['paid']);
        $this->assertSame(1, Order::where('subscription_id', $sub->id)->count(), 'riprende lo stesso ordine');
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(1, $order->payments()->where('method', 'kmoney')->count(), 'KMoney non viene addebitato due volte');
        $this->assertSame(1, $sub->refresh()->cycles_done);
    }

    public function test_when_the_card_rest_keeps_failing_the_kmoney_share_is_refunded_on_pause(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 50]);
        $start = $this->start();
        $this->app->instance(PaymentProvider::class, new class implements PaymentProvider
        {
            public function name(): string
            {
                return 'card-test';
            }

            public function charge(Order $order, array $details = []): PaymentResult
            {
                return new PaymentResult(false, null, 'carta rifiutata');
            }
        });

        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'kmoney');
        $order = Order::where('subscription_id', $sub->id)->firstOrFail();
        $this->assertSame(0, $this->km->refunds, 'ai primi tentativi la quota resta addebitata');
        $this->travelTo($start->addDays(1));
        $this->subs()->runDue();
        $this->travelTo($start->addDays(4));
        $this->subs()->runDue();
        $this->travelTo($start->addDays(9));
        $this->assertSame(1, $this->subs()->runDue()['paused']);

        $this->assertSame('paused', $sub->refresh()->status);
        $this->assertSame(1, $this->km->refunds);
        $this->assertSame('cancelled', $order->refresh()->status);
        $this->assertSame('refunded', $order->payments()->where('method', 'kmoney')->firstOrFail()->status);
    }

    public function test_cancelling_a_subscription_refunds_a_half_paid_order(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 50]);
        $this->app->instance(PaymentProvider::class, new class implements PaymentProvider
        {
            public function name(): string
            {
                return 'card-test';
            }

            public function charge(Order $order, array $details = []): PaymentResult
            {
                return new PaymentResult(false, null, 'carta rifiutata');
            }
        });
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'kmoney');

        $this->subs()->cancel($sub, 'basta');
        $this->assertSame(1, $this->km->refunds);
        $this->assertSame(0, Order::where('subscription_id', $sub->id)->where('status', 'pending_payment')->count());
    }

    public function test_kmoney_account_not_linked_aborts_the_subscription_with_a_clear_message(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00', ['kmoney_percent' => 100]);
        $this->km->linked = false;

        try {
            $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0, [], null, 'kmoney');
            $this->fail('senza conto collegato l\'abbonamento non deve partire');
        } catch (OrderException $e) {
            $this->assertStringContainsString('collega', $e->getMessage());
        }
        $this->assertSame(0, Subscription::whereIn('status', ['active', 'paused'])->count());
        $this->assertSame(0, Order::where('status', 'pending_payment')->count());
    }

    // ── Carta reale (Stripe): la carta si salva al primo pagamento e i mesi dopo si addebita senza il cliente ──

    private function stripe(): void
    {
        config(['lamess.payment_driver' => 'stripe']);
    }

    private function paidEvent(Order $o): array
    {
        return ['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_sub', 'client_reference_id' => (string) $o->id, 'payment_status' => 'paid', 'customer' => 'cus_1',
            'amount_total' => StripeCheckoutProvider::cents((string) $o->total), 'currency' => 'eur', 'payment_intent' => 'pi_first',
        ]]];
    }

    private function hook(array $event)
    {
        $payload = json_encode($event);
        $t = time();
        $sig = "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, self::HOOK);

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    public function test_stripe_first_order_waits_for_the_customer_then_saves_the_card_and_starts_the_calendar(): void
    {
        $this->stripe();
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['id' => 'pi_first', 'payment_method' => 'pm_1'])
            : Http::response(['id' => 'cs_sub', 'url' => 'https://checkout.stripe.test/cs_sub']));
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $start = $this->start();

        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $this->assertSame('pending', $sub->status);
        $this->assertSame(0, $sub->cycles_done);
        $this->assertNull($sub->next_run_at);
        $first = $sub->orders()->firstOrFail();
        $this->assertSame('pending_payment', $first->status);
        $this->assertSame(['paid' => 0, 'failed' => 0, 'paused' => 0], $this->subs()->runDue(), 'finché non paga il primo ordine non parte nulla');

        // La pagina di Stripe viene aperta con l'istruzione di conservare la carta per i prossimi addebiti.
        app(OrderService::class)->startCardCheckout($first, 'https://x.test/ok', 'https://x.test/ko');
        Http::assertSent(function (Request $r) {
            $d = $r->data();

            return str_contains($r->url(), '/checkout/sessions')
                && ($d['customer_creation'] ?? null) === 'always'
                && ($d['payment_intent_data']['setup_future_usage'] ?? null) === 'off_session'
                && ($d['metadata']['subscription_id'] ?? null) !== null;
        });

        $this->hook($this->paidEvent($first))->assertOk();
        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertSame(1, $sub->cycles_done);
        $this->assertSame('cus_1', $sub->stripe_customer_id);
        $this->assertSame('pm_1', $sub->stripe_payment_method_id);
        $this->assertSame('paid', $first->refresh()->status);
        $this->assertEquals($start->addMonthNoOverflow()->toDateString(), $sub->next_run_at->setTimezone('Europe/Rome')->toDateString());

        // Un secondo avviso dello stesso evento non duplica nulla.
        $this->hook($this->paidEvent($first))->assertOk();
        $this->assertSame(1, $sub->refresh()->cycles_done);
    }

    public function test_stripe_next_cycles_charge_the_saved_card_off_session(): void
    {
        $this->stripe();
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['payment_method' => 'pm_1'])
            : Http::response(['id' => 'pi_next', 'status' => 'succeeded']));
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $start = $this->start();
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $this->hook($this->paidEvent($sub->orders()->firstOrFail()));

        $this->travelTo($start->addMonthNoOverflow());
        $this->assertSame(1, $this->subs()->runDue()['paid']);
        Http::assertSent(function (Request $r) {
            $d = $r->data();

            return $r->method() === 'POST' && str_contains($r->url(), '/payment_intents')
                && $d['customer'] === 'cus_1' && $d['payment_method'] === 'pm_1' && $d['off_session'] === 'true' && $d['confirm'] === 'true';
        });
        $this->assertSame(2, $sub->refresh()->cycles_done);
        $this->assertSame(2, Order::where('subscription_id', $sub->id)->where('status', 'paid')->count());
    }

    public function test_stripe_declined_or_3ds_charge_does_not_pay_the_cycle(): void
    {
        $this->stripe();
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['payment_method' => 'pm_1'])
            : Http::response(['error' => ['message' => 'Your card was declined.'], 'status' => 'requires_payment_method'], 402));
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $start = $this->start();
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $this->hook($this->paidEvent($sub->orders()->firstOrFail()));

        $this->travelTo($start->addMonthNoOverflow());
        $this->assertSame(1, $this->subs()->runDue()['failed']);
        $sub->refresh();
        $this->assertSame(1, $sub->retry_count);
        $this->assertSame(1, $sub->cycles_done);
        $this->assertStringContainsString('card was declined', (string) $sub->last_error);
        $this->assertSame(0, Order::where('subscription_id', $sub->id)->where('status', 'pending_payment')->count(), 'nessun ordine in sospeso');
        Notification::assertSentTo($u, SubscriptionChargeFailed::class, fn ($n) => ! $n->paused);

        // Ultimo tentativo fallito: l'abbonamento va in pausa e il cliente lo sa.
        $sub->update(['retry_count' => 3, 'next_run_at' => now()]);
        $this->subs()->runDue();
        Notification::assertSentTo($u, SubscriptionChargeFailed::class, fn ($n) => $n->paused);
    }

    public function test_abandoned_stripe_page_does_not_block_a_new_subscription_and_cancels_the_waiting_order(): void
    {
        $this->stripe();
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $old = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $oldOrder = $old->orders()->firstOrFail();

        $new = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $this->assertSame('pending', $new->status);
        $this->assertSame('cancelled', $old->refresh()->status);
        $this->assertFalse((bool) $old->early_cancel);
        $this->assertSame('cancelled', $oldOrder->refresh()->status);
    }

    // ── Cambio carta ──

    private function activeStripeSub(): array
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00');
        $start = $this->start();
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);
        $this->hook($this->paidEvent($sub->orders()->firstOrFail()));

        return [$u, $sub->refresh(), $start];
    }

    private function setupEvent(Subscription $sub, string $customer = 'cus_1'): array
    {
        return ['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_setup', 'mode' => 'setup', 'payment_status' => 'no_payment_required', 'customer' => $customer, 'setup_intent' => 'seti_1',
            'metadata' => ['subscription_id' => (string) $sub->id, 'purpose' => 'card_update'],
        ]]];
    }

    public function test_customer_can_change_card_and_a_failed_cycle_retries_with_the_new_one(): void
    {
        $this->stripe();
        $declined = true;
        Http::fake(function (Request $r) use (&$declined) {
            if ($r->method() === 'GET') {
                return Http::response(str_contains($r->url(), 'setup_intents') ? ['payment_method' => 'pm_new'] : ['payment_method' => 'pm_old']);
            }
            if (str_contains($r->url(), '/checkout/sessions')) {
                return Http::response(['id' => 'cs_setup', 'url' => 'https://checkout.stripe.test/setup']);
            }

            return $declined && ($r->data()['payment_method'] ?? '') === 'pm_old'
                ? Http::response(['error' => ['message' => 'Your card has expired.']], 402)
                : Http::response(['id' => 'pi_ok', 'status' => 'succeeded']);
        });
        [$u, $sub, $start] = $this->activeStripeSub();
        $this->travelTo($start->addMonthNoOverflow());
        $this->subs()->runDue();
        $this->assertSame(1, $sub->refresh()->retry_count);

        $this->actingAs($u)->post("/area/abbonamenti/{$sub->id}/carta")->assertRedirect('https://checkout.stripe.test/setup');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/checkout/sessions') && ($r->data()['mode'] ?? '') === 'setup' && ($r->data()['customer'] ?? '') === 'cus_1' && ($r->data()['metadata']['subscription_id'] ?? '') === (string) $sub->id);

        $this->hook($this->setupEvent($sub))->assertOk();
        $sub->refresh();
        $this->assertSame('pm_new', $sub->stripe_payment_method_id);
        $this->assertSame(0, $sub->retry_count);
        $this->assertNull($sub->last_error);

        $this->assertSame(1, $this->subs()->runDue()['paid'], 'subito al passaggio successivo, con la carta nuova');
        $this->assertSame(2, $sub->refresh()->cycles_done);
    }

    public function test_card_change_keeps_a_paused_subscription_paused_and_rejects_other_users(): void
    {
        $this->stripe();
        Http::fake(fn (Request $r) => $r->method() === 'GET' ? Http::response(['payment_method' => 'pm_new']) : Http::response(['id' => 'cs', 'url' => 'https://checkout.stripe.test/x']));
        [$u, $sub] = $this->activeStripeSub();
        $this->subs()->pause($sub);

        $this->hook($this->setupEvent($sub))->assertOk();
        $this->assertSame('paused', $sub->refresh()->status);
        $this->assertSame('pm_new', $sub->stripe_payment_method_id);

        $this->actingAs($this->buyer())->post("/area/abbonamenti/{$sub->id}/carta")->assertNotFound();
        $this->actingAs($u)->get('/area/abbonamenti')->assertOk()->assertSee('Cambia carta');
    }

    public function test_card_change_is_not_offered_without_stripe(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '10.00');
        $sub = $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);

        $this->actingAs($u)->post("/area/abbonamenti/{$sub->id}/carta")->assertRedirect('/area/abbonamenti')->assertSessionHasErrors('order');
        $this->actingAs($u)->get('/area/abbonamenti')->assertOk()->assertDontSee('Cambia carta');
    }

    public function test_kmoney_subscription_refuses_bank_transfer_for_the_rest(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00', ['kmoney_percent' => 50]);

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])
            ->post('/area/checkout', $this->billing() + ['subscribe' => 'monthly', 'privacy' => '1', 'payment_method' => 'kmoney', 'kmoney_rest' => 'transfer'])
            ->assertSessionHasErrors('order');
        $this->assertSame(0, Subscription::count());
    }

    public function test_checkout_shows_kmoney_first_and_wording_follows_single_or_monthly(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00', ['kmoney_percent' => 100]);

        // l'abbonamento mensile è preselezionato: l'ordine singolo si sceglie esplicitamente
        $html = $this->actingAs($u)->withSession(['cart' => [$p->id => 1], '_old_input' => ['subscribe' => 'none']])->get('/area/checkout')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'value="card"'), strpos($html, 'value="kmoney"'), 'KMoney è la prima voce');
        $this->assertStringContainsString('Da pagare in KMoney', $html);
        $this->assertStringNotContainsString('Risparmia fino al', $html);
        $this->assertStringNotContainsString('Ogni mese', $html);
        $this->assertStringNotContainsString('dal tuo saldo KMoney', $html);
    }

    public function test_kmoney_option_shows_the_promo_line_and_the_login_or_register_prompt(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '150.00', ['kmoney_percent' => 100]);

        $html = $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->getContent();
        $this->assertStringNotContainsString('Ricarichi solo', $html);
        $this->assertStringNotContainsString('Addebito automatico', $html);
        $this->assertStringContainsString('Da pagare in KMoney', $html);
        $this->assertStringNotContainsString('Risparmia fino al', $html);
        $this->assertStringContainsString('<a href="/area/profilo#kmoney">collega il tuo conto KMoney dal profilo</a>', $html);
        $this->assertStringContainsString('<a href="https://kmoney.it/register" target="_blank" rel="noopener">Apri un conto KMoney</a>', $html);
        $this->assertStringNotContainsString('Ti chiederemo di collegare', $html);

        config(['lamess.kmoney_bonus_percent' => 0]);
        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->assertSee('Da pagare in KMoney');
    }

    public function test_kmoney_prompt_links_to_profile_and_register_with_the_real_provider(): void
    {
        config(['lamess.kmoney_driver' => 'api', 'lamess.kmoney' => ['base_url' => 'https://kmoney.test', 'client_id' => 'c', 'client_secret' => 's', 'seller_account' => 'KYB1', 'register_url' => null]]);
        $this->app->bind(KMoneyProvider::class, fn () => new KMoneyApiProvider);
        $this->app->forgetInstance(OrderService::class);

        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '150.00', ['kmoney_percent' => 100]);
        $html = $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('<a href="/area/profilo#kmoney">collega il tuo conto KMoney dal profilo</a>', $html);
        $this->assertStringContainsString('<a href="https://kmoney.it/register" target="_blank" rel="noopener">Apri un conto KMoney</a>', $html);
    }
}
