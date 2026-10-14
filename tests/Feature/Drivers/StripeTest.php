<?php

declare(strict_types=1);

namespace Tests\Feature\Drivers;

use App\Domain\Onboarding\IdentityVerifier;
use App\Domain\Onboarding\IvdService;
use App\Domain\Onboarding\KycService;
use App\Domain\Onboarding\OnboardingException;
use App\Domain\Onboarding\OnboardingService;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\StripeCheckoutProvider;
use App\Domain\Orders\StripeWebhook;
use App\Domain\System\GoLiveCheck;
use App\Models\IdentityVerification;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class StripeTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private const SECRET = 'whsec_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.payment_driver' => 'stripe', 'lamess.kyc_driver' => 'stripe_identity', 'services.stripe.secret' => 'sk_test_x', 'services.stripe.webhook_secret' => self::SECRET]);
        Notification::fake();
    }

    private function cardOrder(): Order
    {
        $this->plan();
        $p = $this->product('A', '100.00');

        return app(OrderService::class)->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing());
    }

    private function signed(array $event, ?string $secret = null, ?int $t = null): array
    {
        $payload = json_encode($event);
        $t ??= time();

        return [$payload, ['Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, $secret ?? self::SECRET), 'Content-Type' => 'application/json']];
    }

    private function hook(array $event, ?string $secret = null)
    {
        [$payload, $headers] = $this->signed($event, $secret);
        $server = collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all();

        return $this->call('POST', '/webhooks/stripe', [], [], [], $server, $payload);
    }

    private function paidEvent(Order $o, ?int $amount = null): array
    {
        return ['type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_1', 'client_reference_id' => (string) $o->id, 'payment_status' => 'paid',
            'amount_total' => $amount ?? StripeCheckoutProvider::cents((string) $o->total), 'currency' => 'eur', 'payment_intent' => 'pi_123',
        ]]];
    }

    public function test_cents_conversion_rounds_half_up(): void
    {
        $this->assertSame(12200, StripeCheckoutProvider::cents('122.00'));
        $this->assertSame(1, StripeCheckoutProvider::cents('0.005'));
        $this->assertSame(15990, StripeCheckoutProvider::cents('159.90'));
    }

    public function test_signature_check(): void
    {
        $body = '{"a":1}';
        $t = 1_000_000;
        $good = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, 's');
        $this->assertTrue(StripeWebhook::verify($body, $good, 's', 300, $t + 10));
        $this->assertFalse(StripeWebhook::verify($body, $good, 's', 300, $t + 1000), 'oltre la tolleranza');
        $this->assertFalse(StripeWebhook::verify($body.' ', $good, 's', 300, $t));
        $this->assertFalse(StripeWebhook::verify($body, $good, 'altro', 300, $t));
        $this->assertFalse(StripeWebhook::verify($body, null, 's'));
        $this->assertFalse(StripeWebhook::verify($body, $good, '', 300, $t));
    }

    public function test_card_checkout_redirects_to_stripe_and_links_session(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_live_1', 'url' => 'https://checkout.stripe.com/pay/cs_live_1'])]);
        $o = $this->cardOrder();

        $this->actingAs($o->buyer)->post("/area/ordini/{$o->id}/riprova")->assertRedirect('https://checkout.stripe.com/pay/cs_live_1');

        Http::assertSent(fn ($r) => $r['mode'] === 'payment' && $r['client_reference_id'] === (string) $o->id
            && $r['line_items'][0]['price_data']['unit_amount'] === StripeCheckoutProvider::cents((string) $o->total)
            && $r->hasHeader('Authorization', 'Bearer sk_test_x'));
        $this->assertSame('cs_live_1', $o->payments()->where('status', 'pending')->firstOrFail()->provider_ref);
        $this->assertSame('pending_payment', $o->refresh()->status, 'il reindirizzamento non conferma nulla');
    }

    public function test_stripe_failure_keeps_order_pending_with_message(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['error' => 'x'], 500)]);
        $o = $this->cardOrder();

        $this->actingAs($o->buyer)->post("/area/ordini/{$o->id}/riprova")->assertRedirect("/area/ordini/{$o->id}")->assertSessionHasErrors('order');
        $this->assertSame('pending_payment', $o->refresh()->status);
    }

    public function test_webhook_confirms_payment_once_and_issues_invoice(): void
    {
        $o = $this->cardOrder();

        $this->hook($this->paidEvent($o))->assertOk();
        $this->assertSame('paid', $o->refresh()->status);
        $this->assertNotNull($o->invoice);
        $this->assertSame('pi_123', $o->payments()->where('status', 'paid')->firstOrFail()->provider_ref);

        $this->hook($this->paidEvent($o))->assertOk();   // reinvio di Stripe: nessun doppione
        $this->assertSame(1, $o->payments()->where('status', 'paid')->count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_webhook_rejects_bad_signature_and_wrong_amount(): void
    {
        $o = $this->cardOrder();

        $this->hook($this->paidEvent($o), 'segreto-sbagliato')->assertStatus(400);
        $this->assertSame('pending_payment', $o->refresh()->status);

        $this->hook($this->paidEvent($o, 100))->assertOk();   // risponde 200 per non farlo ripetere, ma non conferma
        $this->assertSame('pending_payment', $o->refresh()->status);

        $unpaid = $this->paidEvent($o);
        $unpaid['data']['object']['payment_status'] = 'unpaid';
        $this->hook($unpaid)->assertOk();
        $this->assertSame('pending_payment', $o->refresh()->status);
    }

    public function test_stripe_charge_never_succeeds_server_side(): void
    {
        $o = $this->cardOrder();
        $this->assertFalse(app(OrderService::class)->payByCard($o));
        $this->assertSame('pending_payment', $o->refresh()->status);
    }

    public function test_checkout_accepts_only_cards_in_italian(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_only_card', 'url' => 'https://checkout.stripe.com/pay/cs_only_card'])]);
        $o = $this->cardOrder();

        $this->actingAs($o->buyer)->post("/area/ordini/{$o->id}/riprova")->assertRedirect();

        Http::assertSent(fn ($r) => $r['payment_method_types'] === ['card'] && $r['locale'] === 'it');
    }

    public function test_return_from_stripe_confirms_the_order_by_asking_stripe_not_the_url(): void
    {
        $o = $this->cardOrder();
        $o->payments()->where('status', 'pending')->update(['provider' => 'stripe', 'provider_ref' => 'cs_ret_1']);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_ret_1' => Http::response([
            'id' => 'cs_ret_1', 'client_reference_id' => (string) $o->id, 'payment_status' => 'paid', 'mode' => 'payment',
            'amount_total' => StripeCheckoutProvider::cents((string) $o->total), 'currency' => 'eur', 'payment_intent' => 'pi_ret',
        ])]);

        $this->actingAs($o->buyer)->get("/area/ordini/{$o->id}?da=carta")->assertOk();

        $this->assertSame('paid', $o->refresh()->status);
        $this->assertSame('pi_ret', $o->payments()->where('status', 'paid')->firstOrFail()->provider_ref);
    }

    public function test_return_from_stripe_does_not_confirm_when_stripe_says_unpaid_or_is_unreachable(): void
    {
        $o = $this->cardOrder();
        $o->payments()->where('status', 'pending')->update(['provider' => 'stripe', 'provider_ref' => 'cs_ret_2']);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_ret_2' => Http::response([
            'id' => 'cs_ret_2', 'client_reference_id' => (string) $o->id, 'payment_status' => 'unpaid', 'amount_total' => 1, 'currency' => 'eur',
        ])]);
        $this->actingAs($o->buyer)->get("/area/ordini/{$o->id}?da=carta")->assertOk();
        $this->assertSame('pending_payment', $o->refresh()->status);

        Http::fake(['api.stripe.com/*' => Http::response([], 500)]);
        $this->actingAs($o->buyer)->get("/area/ordini/{$o->id}?da=carta")->assertOk();
        $this->assertSame('pending_payment', $o->refresh()->status);
    }

    public function test_reconcile_refuses_a_session_that_belongs_to_another_order(): void
    {
        $o = $this->cardOrder();
        $o->payments()->where('status', 'pending')->update(['provider' => 'stripe', 'provider_ref' => 'cs_other']);
        Http::fake(['api.stripe.com/v1/checkout/sessions/cs_other' => Http::response([
            'id' => 'cs_other', 'client_reference_id' => '999999', 'payment_status' => 'paid', 'amount_total' => StripeCheckoutProvider::cents((string) $o->total), 'currency' => 'eur',
        ])]);

        $this->assertSame('unknown_order', app(StripeWebhook::class)->reconcile($o, app(PaymentProvider::class)));
        $this->assertSame('pending_payment', $o->refresh()->status);
    }

    public function test_card_is_offered_only_when_stripe_is_really_configured(): void
    {
        $this->assertTrue(app(OrderService::class)->enabledMethods()['card']);

        config(['services.stripe.secret' => '']);
        $this->assertFalse(app(OrderService::class)->enabledMethods()['card'], 'senza chiave Stripe la carta sparisce dal checkout');
    }

    public function test_test_driver_can_never_take_card_payments_in_production(): void
    {
        config(['lamess.payment_driver' => 'fake']);
        $o = $this->cardOrder();
        $this->assertTrue(app(OrderService::class)->enabledMethods()['card']);   // in sviluppo resta utilizzabile

        $this->app['env'] = 'production';
        $this->assertFalse(app(OrderService::class)->enabledMethods()['card']);
        $this->assertFalse(app(OrderService::class)->payByCard($o), 'nessuna carta risulta pagata senza Stripe');
        $this->assertSame('pending_payment', $o->refresh()->status);
    }

    public function test_card_orders_cannot_be_confirmed_by_hand(): void
    {
        $o = $this->cardOrder();
        $this->assertTrue($o->paysByCard());
        $transfer = app(OrderService::class)->place($o->buyer, [$o->items()->first()->product_id => 1], 'IT', 'transfer', $this->billing());
        $this->assertFalse($transfer->paysByCard());
    }

    private function consultantWithOnlineKyc(): array
    {
        $fan = $this->fan($this->activeSponsor());
        $c = app(IvdService::class)->accept($this->signedContract($fan), 'admin')->consultant;

        return [$c, $fan->user];
    }

    public function test_online_identity_start_and_verified_webhook_satisfy_id_document_only(): void
    {
        Http::fake(['api.stripe.com/v1/identity/verification_sessions' => Http::response(['id' => 'vs_1', 'url' => 'https://verify.stripe.com/start/vs_1'])]);
        [$c, $user] = $this->consultantWithOnlineKyc();

        $this->actingAs($user)->post('/area/onboarding/verifica-online')->assertRedirect('https://verify.stripe.com/start/vs_1');
        $this->assertSame('submitted', $c->refresh()->kyc_status);

        $this->hook(['type' => 'identity.verification_session.verified', 'data' => ['object' => ['id' => 'vs_1']]])->assertOk();
        $this->assertSame('verified', IdentityVerification::firstOrFail()->status);
        $this->assertSame('submitted', $c->refresh()->kyc_status, 'manca ancora la foto del tesserino');

        $photo = app(KycService::class)->upload($c, 'photo', UploadedFile::fake()->image('f.jpg'));
        app(KycService::class)->review($photo, true, 'admin');
        $this->assertSame('verified', $c->refresh()->kyc_status);
        $this->assertContains('IBAN e intestatario', app(OnboardingService::class)->missing($c));
    }

    public function test_requires_input_does_not_verify_and_unknown_session_is_ignored(): void
    {
        [$c] = $this->consultantWithOnlineKyc();
        IdentityVerification::create(['consultant_id' => $c->id, 'provider' => 'stripe_identity', 'ref' => 'vs_2']);

        $this->hook(['type' => 'identity.verification_session.requires_input', 'data' => ['object' => ['id' => 'vs_2', 'last_error' => ['code' => 'document_expired']]]])->assertOk();
        $v = IdentityVerification::firstOrFail();
        $this->assertSame('requires_input', $v->status);
        $this->assertSame('document_expired', $v->failure_reason);
        $this->assertNotSame('verified', $c->refresh()->kyc_status);

        $this->hook(['type' => 'identity.verification_session.verified', 'data' => ['object' => ['id' => 'vs_sconosciuta']]])->assertOk();
        $this->assertSame(1, IdentityVerification::count());
    }

    public function test_manual_driver_has_no_online_verification(): void
    {
        config(['lamess.kyc_driver' => 'manual']);
        [$c] = $this->consultantWithOnlineKyc();

        $this->assertFalse(app(IdentityVerifier::class)->available());
        $this->expectException(OnboardingException::class);
        app(KycService::class)->startOnline($c, app(IdentityVerifier::class), 'https://x.test');
    }

    public function test_go_live_check_flags_missing_stripe_secrets_and_seller_data(): void
    {
        config(['services.stripe.webhook_secret' => '', 'lamess.invoice_sink_driver' => 'mail', 'lamess.sdi.mail_to' => 'x@y.it']);
        $rows = collect((new GoLiveCheck)->run())->keyBy('label');

        $this->assertSame('fail', $rows['Stripe: chiavi e webhook']['status']);
        $this->assertSame('fail', $rows['Dati del venditore per la fattura elettronica']['status']);
        $this->assertSame('ok', $rows['Invio delle fatture al gestionale/SDI']['status']);
        $this->assertSame('ok', $rows['Verifica identità online']['status']);
    }
}
