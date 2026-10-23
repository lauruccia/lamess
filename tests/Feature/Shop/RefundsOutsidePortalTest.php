<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PaymentResult;
use App\Domain\Orders\RefundService;
use App\Domain\Orders\RefundsCardPayments;
use App\Domain\Orders\ReturnService;
use App\Domain\Subscriptions\SubscriptionDigest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Storni fatti fuori dal portale (dashboard Stripe, KMoney), contestazioni della banca e abbonamenti collegati. */
final class RefundsOutsidePortalTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private const HOOK = 'whsec_test';

    private object $card;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.stripe.webhook_secret' => self::HOOK, 'lamess.kmoney.webhook_secret' => 'whk']);
        $this->card = new class
        {
            public int $refunds = 0;
        };
        $card = $this->card;
        $this->app->instance(PaymentProvider::class, new class($card) implements PaymentProvider, RefundsCardPayments
        {
            public function __construct(private readonly object $s) {}

            public function name(): string
            {
                return 'stripe';
            }

            public function charge(Order $order, array $details = []): PaymentResult
            {
                return new PaymentResult(true, 'pi_test'.$order->id);
            }

            public function refundPayment(Payment $payment, string $amount, string $idempotencyKey): PaymentResult
            {
                $this->s->refunds++;

                return new PaymentResult(true, 're_'.$payment->id);
            }
        });
    }

    private function paidCardOrder(int $qty = 2): Order
    {
        $this->plan();
        $this->shipping('10.00');
        $p = Product::first() ?? $this->product('A', '123.00');
        $orders = app(OrderService::class);
        $order = $orders->place($this->buyer($this->activeSponsor()), [$p->id => $qty], 'IT', 'card', $this->billing());
        $orders->payByCard($order);

        return $order->fresh();
    }

    private function hook(array $event)
    {
        $payload = json_encode($event);
        $t = time();

        return $this->call('POST', '/webhooks/stripe', [], [], [], ['HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, self::HOOK), 'CONTENT_TYPE' => 'application/json'], $payload);
    }

    private function refundedEvent(Order $o, int $cents): array
    {
        $pi = (string) $o->payments()->where('status', 'paid')->value('provider_ref');

        return ['type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_1', 'payment_intent' => $pi, 'amount_refunded' => $cents, 'refunds' => ['data' => [['id' => 're_ext']]]]]];
    }

    public function test_refund_done_on_stripe_is_recorded_once_and_flagged(): void
    {
        $order = $this->paidCardOrder();
        $cents = (int) round((float) $order->total * 100);

        $this->hook($this->refundedEvent($order, $cents))->assertOk();
        $ext = RefundService::externalPending($order);
        $this->assertCount(1, $ext);
        $this->assertSame([Refund::DONE, 'card', 're_ext'], [$ext[0]->status, $ext[0]->method, $ext[0]->provider_ref]);
        $this->assertEqualsWithDelta((float) $order->total, (float) $ext[0]->amount, 0.001);
        $this->assertSame('paid', $order->refresh()->status, 'lo stato non cambia da solo: fattura e compensi vanno stornati con un reso');
        $this->assertTrue($order->events()->where('type', 'refund_external')->where('internal', true)->exists());

        $this->hook($this->refundedEvent($order, $cents))->assertOk();
        $this->assertCount(1, RefundService::externalPending($order), 'la stessa notifica ripetuta non duplica il rimborso');
    }

    public function test_refund_made_from_the_portal_is_not_recorded_again_by_the_webhook(): void
    {
        $order = $this->paidCardOrder();
        $note = app(ReturnService::class)->refund($order, [$order->items->first()->id => 1], 'reso');
        app(RefundService::class)->payBack($order->fresh(), $note);
        $done = (int) round((float) Refund::where('order_id', $order->id)->sum('amount') * 100);

        $this->hook($this->refundedEvent($order, $done))->assertOk();
        $this->assertSame(1, Refund::where('order_id', $order->id)->count());
        $this->assertCount(0, RefundService::externalPending($order));
    }

    public function test_return_after_an_external_refund_covers_it_and_does_not_pay_the_customer_twice(): void
    {
        $order = $this->paidCardOrder();
        $this->hook($this->refundedEvent($order, (int) round((float) $order->total * 100)))->assertOk();

        $note = app(ReturnService::class)->refund($order->fresh(), $order->items->mapWithKeys(fn ($i) => [$i->id => $i->qty])->all(), 'Rimborso già eseguito fuori dal portale', false, true);
        $this->assertCount(0, RefundService::externalPending($order), 'ora il rimborso è coperto dalla nota di credito');
        $this->assertSame($note->id, Refund::where('order_id', $order->id)->value('credit_note_id'));

        app(RefundService::class)->payBack($order->fresh(), $note, 'reso');
        $this->assertSame(0, $this->card->refunds, 'nessun secondo rimborso su Stripe');
        $this->assertSame('refunded', $order->refresh()->status);
    }

    public function test_chargeback_pauses_the_subscription_and_is_logged_once(): void
    {
        $order = $this->paidCardOrder(1);
        $sub = Subscription::create([
            'user_id' => $order->buyer_user_id, 'status' => Subscription::ACTIVE, 'lines' => [], 'country_code' => 'IT', 'billing' => [], 'payment_method' => 'card',
            'interval_months' => 1, 'commitment_months' => 12, 'free_shipping' => true, 'started_at' => now(), 'next_run_at' => now()->addMonth(), 'cycles_done' => 1,
        ]);
        $order->update(['subscription_id' => $sub->id]);
        $pi = (string) $order->payments()->where('status', 'paid')->value('provider_ref');
        $created = ['type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_1', 'payment_intent' => $pi, 'amount' => 12300, 'reason' => 'fraudulent', 'evidence_details' => ['due_by' => now()->addDays(10)->timestamp]]]];

        $this->hook($created)->assertOk();
        $this->assertSame(Subscription::PAUSED, $sub->refresh()->status);
        $this->assertSame(1, $order->events()->where('type', 'dispute')->count());
        $this->assertStringContainsString('fraudulent', (string) $order->events()->where('type', 'dispute')->value('message'));

        $this->hook($created)->assertOk();
        $this->assertSame(1, $order->events()->where('type', 'dispute')->count(), 'notifica ripetuta: una sola nota');

        $this->hook(['type' => 'charge.dispute.closed', 'data' => ['object' => ['id' => 'dp_1', 'payment_intent' => $pi, 'amount' => 12300, 'status' => 'lost']]])->assertOk();
        $this->assertStringContainsString('PERSA', (string) $order->events()->where('type', 'dispute_closed')->value('message'));
    }

    public function test_unknown_payment_is_ignored_without_errors(): void
    {
        $this->hook(['type' => 'charge.refunded', 'data' => ['object' => ['id' => 'ch_x', 'payment_intent' => 'pi_nonesiste', 'amount_refunded' => 500]]])->assertOk();
        $this->hook(['type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_x', 'payment_intent' => 'pi_nonesiste', 'amount' => 500]]])->assertOk();
        $this->assertSame(0, Refund::count());
    }

    public function test_kmoney_refund_notice_leaves_a_note_and_changes_nothing(): void
    {
        $order = $this->paidCardOrder();
        $payload = json_encode(['event' => 'payment_request.refunded', 'payload' => ['external_order_uuid' => $order->number]]);
        $this->call('POST', '/webhooks/kmoney', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_KMONEY_SIGNATURE' => 'sha256='.hash_hmac('sha256', (string) $payload, 'whk')], (string) $payload)->assertOk();

        $this->assertSame('paid', $order->refresh()->status);
        $this->assertTrue($order->events()->where('type', 'kmoney_external')->exists());
    }

    public function test_admin_digest_lists_external_refunds_and_disputes(): void
    {
        config(['lamess.subscription.admin_mail_to' => 'admin@example.test']);
        $order = $this->paidCardOrder();
        $this->assertSame([], array_keys(app(SubscriptionDigest::class)->build()));

        $this->hook($this->refundedEvent($order, (int) round((float) $order->total * 100)))->assertOk();
        $pi = (string) $order->payments()->where('status', 'paid')->value('provider_ref');
        $this->hook(['type' => 'charge.dispute.created', 'data' => ['object' => ['id' => 'dp_9', 'payment_intent' => $pi, 'amount' => 100, 'reason' => 'general']]])->assertOk();

        $sections = app(SubscriptionDigest::class)->build();
        $this->assertArrayHasKey('Rimborsi fatti fuori dal portale da regolarizzare (fattura e compensi da stornare)', $sections);
        $this->assertArrayHasKey('Contestazioni della banca (chargeback) nelle ultime 24 ore', $sections);
    }
}
