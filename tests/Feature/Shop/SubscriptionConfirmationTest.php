<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PaymentResult;
use App\Domain\Orders\StripeCheckoutProvider;
use App\Domain\Subscriptions\SubscriptionDigest;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\OrderPaid;
use App\Notifications\SubscriptionCardExpiring;
use App\Notifications\SubscriptionChargeFailed;
use App\Notifications\SubscriptionChargeReminder;
use App\Notifications\SubscriptionsAdminDigest;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Conferma KMoney (402), tentativi a 1/3/5 giorni, riepilogo admin, avviso 12º mese, carta in scadenza. */
final class SubscriptionConfirmationTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private const URL = 'https://kmoney.test/confirm/abc';

    private object $km;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->km = new class
        {
            /** Link di conferma: se valorizzato KMoney risponde «serve la conferma del cliente». */
            public ?string $confirm = null;

            public bool $funds = true;

            public int $charges = 0;
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
                return true;
            }

            public function refund(Order $order, User $buyer): PaymentResult
            {
                return new PaymentResult(true, 'RF-'.$order->id);
            }

            public function charge(Order $order, User $buyer): PaymentResult
            {
                $this->s->charges++;
                if ($this->s->confirm !== null) {
                    return new PaymentResult(false, null, 'confirmation_required', $this->s->confirm);
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

    /** @return array{0:User,1:Subscription} */
    private function kmoneySub(int $commitment = 0): array
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '123.00', ['kmoney_percent' => 100]);

        return [$u, $this->subs()->subscribe($u, [$p->id => 1], 'IT', $this->billing(), $commitment, [], null, 'kmoney')];
    }

    public function test_kmoney_confirmation_keeps_the_order_waiting_and_sends_the_confirm_link(): void
    {
        $this->start();
        $this->km->confirm = self::URL;
        [$u, $sub] = $this->kmoneySub();

        $order = Order::where('subscription_id', $sub->id)->firstOrFail();
        $this->assertSame('pending_payment', $order->status, 'se l\'ordine fosse annullato il pagamento confermato dal cliente andrebbe perso');
        $this->assertSame(SubscriptionService::CONFIRM, $sub->last_error);
        $this->assertSame(1, $sub->retry_count);
        Notification::assertSentTo($u, SubscriptionChargeFailed::class, fn ($n) => $n->confirmUrl === self::URL && $n->retryDays === 1 && ! $n->paused);
    }

    public function test_confirmed_order_closes_the_month_without_a_second_charge(): void
    {
        $start = $this->start();
        $this->km->confirm = self::URL;
        [$u, $sub] = $this->kmoneySub();
        $order = Order::where('subscription_id', $sub->id)->firstOrFail();
        $this->assertSame(0, $sub->cycles_done);

        // Il cliente conferma su KMoney: il webhook firmato chiude l'ordine.
        config(['lamess.kmoney.webhook_secret' => 'whk']);
        $payload = json_encode(['event' => 'payment_request.paid', 'payload' => [
            'external_order_uuid' => $order->number, 'amount' => (int) round((float) $order->kmoney_amount * 100), 'transfer_uuid' => 'tr-1',
        ]]);
        $this->call('POST', '/webhooks/kmoney', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_KMONEY_SIGNATURE' => 'sha256='.hash_hmac('sha256', (string) $payload, 'whk')], (string) $payload)->assertOk();

        $sub->refresh();
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame(1, $sub->cycles_done, 'il calendario avanza subito');
        $this->assertSame(0, $sub->retry_count);
        $this->assertNull($sub->last_error);
        $this->assertTrue($sub->next_run_at->greaterThan($start));
        $this->assertSame(1, Order::where('subscription_id', $sub->id)->count(), 'nessun secondo ordine');
    }

    public function test_nightly_run_recognizes_an_order_paid_outside_the_cycle(): void
    {
        $this->start();
        $this->km->confirm = self::URL;
        [, $sub] = $this->kmoneySub();
        $order = Order::where('subscription_id', $sub->id)->firstOrFail();
        app(OrderService::class)->settleKMoney($order, 'tr-2');

        $this->travelTo($sub->refresh()->next_run_at->addMinute());
        $charges = $this->km->charges;
        $this->assertSame(1, $this->subs()->runDue()['paid']);
        $this->assertSame($charges, $this->km->charges, 'niente nuovo addebito');
        $this->assertSame(1, Order::where('subscription_id', $sub->id)->count());
        $this->assertSame(1, $sub->refresh()->cycles_done);
    }

    public function test_admin_resend_does_not_use_up_attempts_or_move_the_schedule(): void
    {
        $this->start();
        $this->km->confirm = self::URL;
        [$u, $sub] = $this->kmoneySub();
        $sub->refresh();
        [$tries, $next] = [$sub->retry_count, $sub->next_run_at];

        $this->km->confirm = 'https://kmoney.test/confirm/nuovo';
        $this->assertSame('failed', $this->subs()->requestConfirmation($sub));
        $sub->refresh();
        $this->assertSame($tries, $sub->retry_count);
        $this->assertTrue($next->equalTo($sub->next_run_at));
        $this->assertSame(SubscriptionService::CONFIRM, $sub->last_error);
        Notification::assertSentTo($u, SubscriptionChargeFailed::class, fn ($n) => $n->confirmUrl === 'https://kmoney.test/confirm/nuovo');
        $this->assertSame(1, Order::where('subscription_id', $sub->id)->count(), 'l\'ordine in sospeso si riusa');

        // Con la conferma data nel frattempo l'addebito va a buon fine.
        $this->km->confirm = null;
        $this->assertSame('paid', $this->subs()->requestConfirmation($sub->refresh()));
        $this->assertSame(1, $sub->refresh()->cycles_done);
    }

    public function test_retries_after_1_3_and_5_days_with_a_different_message_each_time_then_pause(): void
    {
        $start = $this->start();
        $this->km->funds = false;
        [$u, $sub] = $this->kmoneySub();

        $this->assertSame(1, $sub->retry_count);
        $this->assertTrue($sub->next_run_at->equalTo($start->addDays(1)));
        Notification::assertSentTo($u, SubscriptionChargeFailed::class, fn ($n) => $n->attempt === 1 && $n->retryDays === 1 && ! $n->paused);

        $this->travelTo($start->addDays(1));
        $this->subs()->runDue();
        $sub->refresh();
        $this->assertSame(2, $sub->retry_count);
        $this->assertTrue($sub->next_run_at->equalTo($start->addDays(4)));
        Notification::assertSentTo($u, SubscriptionChargeFailed::class, fn ($n) => $n->attempt === 2 && $n->retryDays === 3);

        $this->travelTo($start->addDays(4));
        $this->subs()->runDue();
        $sub->refresh();
        $this->assertSame(3, $sub->retry_count);
        $this->assertTrue($sub->next_run_at->equalTo($start->addDays(9)));
        $last = null;
        Notification::assertSentTo($u, SubscriptionChargeFailed::class, function ($n) use (&$last) {
            if ($n->attempt === 3) {
                $last = $n;
            }

            return $n->attempt === 3 && $n->retryDays === 5;
        });
        $this->assertStringContainsString('Ultimo avviso', $last->toMail($u)->subject);

        $this->travelTo($start->addDays(9));
        $this->assertSame(1, $this->subs()->runDue()['paused']);
        $this->assertSame('paused', $sub->refresh()->status);
        Notification::assertSentTo($u, SubscriptionChargeFailed::class, fn ($n) => $n->paused);
    }

    public function test_kmoney_confirmation_email_has_the_confirm_button(): void
    {
        [$u, $sub] = [$this->buyer($this->activeSponsor()), new Subscription(['payment_method' => 'kmoney'])];
        $mail = (new SubscriptionChargeFailed($sub, false, 1, self::URL, 1, 4))->toMail($u);
        $this->assertSame('Conferma l\'addebito del tuo abbonamento Lamèss', $mail->subject);
        $this->assertSame(self::URL, $mail->actionUrl);
        $this->assertSame('Conferma l\'addebito', $mail->actionText);
    }

    public function test_reminder_before_the_charge_that_completes_the_commitment_says_it_continues(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $sub = new Subscription(['commitment_months' => 12, 'cycles_done' => 12, 'interval_months' => 1, 'payment_method' => 'card']);
        $sub->setRelation('user', $u);
        $lines = (new SubscriptionChargeReminder($sub, '10,00'))->toMail($u)->introLines;
        $this->assertStringContainsString('Hai completato i 12 mesi', implode(' ', $lines));

        $sub->cycles_done = 5;
        $this->assertStringNotContainsString('Hai completato', implode(' ', (new SubscriptionChargeReminder($sub, '10,00'))->toMail($u)->introLines));
    }

    public function test_paid_order_email_of_a_subscription_tells_the_next_charge_date(): void
    {
        $this->start();
        [$u, $sub] = $this->kmoneySub(12);
        $order = Order::where('subscription_id', $sub->id)->firstOrFail();
        $this->assertSame('paid', $order->status);
        $text = implode(' ', (new OrderPaid($order->refresh()))->toMail($u)->introLines);
        $this->assertStringContainsString('prossimo addebito del tuo abbonamento sarà il '.$sub->refresh()->next_run_at->setTimezone('Europe/Rome')->format('d/m/Y'), $text);
    }

    public function test_admin_digest_lists_what_needs_attention_and_stays_silent_otherwise(): void
    {
        $this->start();
        config(['lamess.subscription.admin_mail_to' => 'admin@example.test']);
        $this->assertSame(0, app(SubscriptionDigest::class)->send(), 'niente da segnalare, nessuna email');

        $this->km->funds = false;
        [, $sub] = $this->kmoneySub();
        $this->assertSame(1, app(SubscriptionDigest::class)->send());
        Notification::assertSentOnDemand(SubscriptionsAdminDigest::class, fn ($n, $channels, $notifiable) => isset($n->sections['Addebiti non riusciti (si riprova da soli)']) && $notifiable->routes['mail'] === ['admin@example.test']);

        $sub->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now(), 'early_cancel' => true, 'cancel_reason' => 'costa troppo']);
        $sections = app(SubscriptionDigest::class)->build();
        $this->assertArrayHasKey('Disdette nelle ultime 24 ore', $sections);
        $this->assertStringContainsString('DISDETTA ANTICIPATA', $sections['Disdette nelle ultime 24 ore'][0]['dettaglio']);
    }

    public function test_card_expiring_before_the_next_charge_is_notified_once(): void
    {
        $this->start();
        $this->app->instance(PaymentProvider::class, new StripeCheckoutProvider('sk_test_x'));
        $u = $this->buyer($this->activeSponsor());
        $sub = Subscription::create([
            'user_id' => $u->id, 'status' => Subscription::ACTIVE, 'lines' => [], 'country_code' => 'IT', 'billing' => [], 'payment_method' => 'card',
            'interval_months' => 1, 'commitment_months' => 12, 'free_shipping' => true, 'started_at' => now()->subMonths(2), 'next_run_at' => now()->addDays(10),
            'stripe_customer_id' => 'cus_1', 'stripe_payment_method_id' => 'pm_1', 'cycles_done' => 2,
        ]);
        Http::fake(['api.stripe.com/v1/payment_methods/pm_1' => Http::response(['card' => ['exp_month' => (int) now()->addDays(5)->format('n'), 'exp_year' => (int) now()->addDays(5)->format('Y')]])]);

        $this->assertSame(1, $this->subs()->sendCardExpiryNotices());
        Notification::assertSentTo($u, SubscriptionCardExpiring::class);
        $this->assertSame(0, $this->subs()->sendCardExpiryNotices(), 'una sola email per carta');

        // Carta valida a lungo: nessun avviso.
        $sub->update(['stripe_payment_method_id' => 'pm_2']);
        Http::fake(['api.stripe.com/v1/payment_methods/pm_2' => Http::response(['card' => ['exp_month' => 12, 'exp_year' => (int) now()->addYears(3)->format('Y')]])]);
        $this->assertSame(0, $this->subs()->sendCardExpiryNotices());
    }

    public function test_order_list_filter_hides_failed_attempts_of_a_live_subscription(): void
    {
        $this->start();
        $this->km->funds = false;
        [, $sub] = $this->kmoneySub();
        $first = Order::where('subscription_id', $sub->id)->orderBy('id')->firstOrFail();
        $this->assertSame('cancelled', $first->status, 'il primo tentativo fallito lascia un ordine annullato');
        $this->assertSame('active', $sub->refresh()->status);
        $this->travelTo($sub->refresh()->next_run_at);
        $this->subs()->runDue();
        $second = Order::where('subscription_id', $sub->id)->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertTrue($second->events()->where('type', 'charge_failed')->exists(), 'il motivo resta nella cronologia');

        $filter = fn () => Order::query()->whereNot(fn ($w) => $w->where('orders.status', 'cancelled')->whereNotNull('orders.subscription_id')
            ->whereIn('orders.subscription_id', fn ($q) => $q->select('id')->from('subscriptions')->whereIn('status', ['active', 'paused'])))->pluck('id')->all();
        $this->assertNotContains($first->id, $filter());
        $this->assertNotContains($second->id, $filter());

        // Abbonamento disdetto: i suoi ordini annullati tornano visibili.
        $this->subs()->cancel($sub->refresh(), 'prova');
        $this->assertContains($first->id, $filter());
    }
}
