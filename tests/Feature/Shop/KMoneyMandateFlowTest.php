<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\KMoneyLink;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Collegamento KMoney col driver «api»: consenso OAuth, poi la pagina del mandato su KMoney (rotta a sé) e il ritorno. */
final class KMoneyMandateFlowTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'lamess.kmoney_driver' => 'api',
            'lamess.payments.kmoney' => true,
            'lamess.kmoney.base_url' => 'https://kmoney.test',
            'lamess.kmoney.client_id' => 'kshop-prova',
            'lamess.kmoney.client_secret' => 'segreto',
            'lamess.kmoney.seller_account' => 'KYBPROVA0000001',
        ]);
        $this->plan();
        $this->shipping('5.00');
    }

    private function linkedWithoutMandate(): array
    {
        $user = $this->buyer($this->activeSponsor());
        $link = KMoneyLink::create(['user_id' => $user->id, 'access_token' => 'at', 'expires_at' => now()->addHour()]);

        return [$user, $link];
    }

    public function test_authorize_payments_sends_the_linked_customer_to_the_kmoney_mandate_page(): void
    {
        [$user] = $this->linkedWithoutMandate();

        $res = $this->actingAs($user)->get('/area/kmoney/mandato?da=profilo');

        $res->assertRedirectContains('https://kmoney.test/oauth/mandate?');
        parse_str((string) parse_url((string) $res->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertSame('kshop-prova', $q['client_id']);
        $this->assertSame('KYBPROVA0000001', $q['seller']);
        $this->assertSame(url('/area/kmoney/ritorno'), $q['return_url']);
        $res->assertSessionHas('kmoney_from', 'profilo');
    }

    public function test_authorize_payments_without_a_linked_account_goes_back_to_the_profile(): void
    {
        $user = $this->buyer($this->activeSponsor());

        $this->actingAs($user)->get('/area/kmoney/mandato?da=profilo')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHasErrors('kmoney');
    }

    public function test_returning_from_consent_without_a_mandate_goes_on_to_the_mandate_page(): void
    {
        $user = $this->buyer($this->activeSponsor());
        Http::fake([
            'kmoney.test/api/oauth/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3600]),
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => []]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
        ]);

        $this->actingAs($user)
            ->withSession(['kmoney_oauth' => ['state' => 'S', 'verifier' => 'V'], 'kmoney_from' => 'profilo'])
            ->get('/area/kmoney/ritorno?code=C&state=S')
            ->assertRedirectContains('https://kmoney.test/oauth/mandate?')
            ->assertSessionHas('kmoney_from', 'profilo');

        $this->assertNull(KMoneyLink::where('user_id', $user->id)->first()->mandate_uuid);
    }

    public function test_returning_from_the_mandate_page_saves_the_mandate_read_back_from_kmoney(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        Http::fake([
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => [['uuid' => 'M-1']]]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
        ]);

        $this->actingAs($user)->withSession(['kmoney_from' => 'profilo'])
            ->get('/area/kmoney/ritorno?mandate=granted')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHas('status');

        $this->assertSame('M-1', $link->refresh()->mandate_uuid);
    }

    public function test_the_granted_parameter_alone_is_not_trusted(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        Http::fake([
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => []]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
        ]);

        $this->actingAs($user)->withSession(['kmoney_from' => 'profilo'])
            ->get('/area/kmoney/ritorno?mandate=granted')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHasErrors('kmoney');

        $this->assertNull($link->refresh()->mandate_uuid);
    }

    public function test_refusing_the_mandate_shows_a_message_and_does_not_loop_back_to_kmoney(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        Http::fake();

        $this->actingAs($user)->withSession(['kmoney_from' => 'profilo'])
            ->get('/area/kmoney/ritorno?mandate=denied')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHasErrors('kmoney');

        Http::assertNothingSent();
        $this->assertNull($link->refresh()->mandate_uuid);
    }

    private function checkoutForm(array $over = []): array
    {
        return $over + $this->billing() + ['payment_method' => 'kmoney', 'privacy' => '1', 'subscribe' => 'none'];
    }

    public function test_checkout_with_kmoney_and_no_linked_account_creates_no_order_and_shows_the_connect_and_register_links(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $res = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm());

        $res->assertSessionHasErrors('order');
        $res->assertSessionHas('kmoney_unlinked.connect', url('/area/kmoney/collega'));
        $res->assertSessionHas('kmoney_unlinked.register', 'https://kmoney.it/register');
        $this->assertSame(0, Order::count(), 'l\'ordine non si crea e non resta in sospeso');

        $this->actingAs($user)->from('/area/checkout')->withSession(['cart' => [$p->id => 1]])->followingRedirects()->post('/area/checkout', $this->checkoutForm())
            ->assertSee('Per pagare con KMoney')
            ->assertSee('collega il tuo conto')
            ->assertSee('clicca qui')
            ->assertSee(url('/area/kmoney/collega'), false)
            ->assertSee('https://kmoney.it/register', false);
    }

    public function test_a_linked_account_without_the_payment_mandate_cannot_confirm_the_order_either(): void
    {
        [$user] = $this->linkedWithoutMandate();
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm())
            ->assertSessionHasErrors('order')->assertSessionHas('kmoney_unlinked');
        $this->assertSame(0, Order::count());
    }

    public function test_the_monthly_subscription_with_kmoney_is_blocked_too_when_the_account_is_not_linked(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm(['subscribe' => 'monthly']))
            ->assertSessionHasErrors('order')->assertSessionHas('kmoney_unlinked');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_with_the_account_linked_and_authorized_confirm_order_charges_kmoney_in_one_click(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        $link->update(['mandate_uuid' => 'M-1']);
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        Http::fake(['kmoney.test/api/v1/mandates/M-1/charge' => Http::response(['status' => 'booked', 'transfer_uuid' => 'T-1'], 200)]);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm())
            ->assertSessionHasNoErrors();
        $order = Order::where('payment_method', 'kmoney')->firstOrFail();
        $this->assertContains($order->status, Order::PAID_STATES, 'nessuna seconda conferma: «Conferma ordine» addebita subito');
        Http::assertSentCount(1);
    }

    public function test_when_kmoney_asks_for_one_confirmation_the_customer_is_sent_there_and_the_order_waits(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        $link->update(['mandate_uuid' => 'M-1']);
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        Http::fake(['kmoney.test/api/v1/mandates/M-1/charge' => Http::response(['reason' => 'amount_over_cap', 'confirmation_url' => 'https://kmoney.test/pay/abc'], 402)]);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm())
            ->assertRedirect('https://kmoney.test/pay/abc')->assertSessionHas('kmoney_order');
        $this->assertSame('pending_payment', Order::where('payment_method', 'kmoney')->firstOrFail()->status);
    }

    public function test_after_confirming_on_kmoney_the_order_page_hides_confirm_and_pay_until_the_result_arrives(): void
    {
        [$user, $link] = $this->linkedWithoutMandate();
        $link->update(['mandate_uuid' => 'M-1']);
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        Http::fake(['kmoney.test/api/v1/mandates/M-1/charge' => Http::response(['reason' => 'amount_over_cap', 'confirmation_url' => 'https://kmoney.test/pay/abc'], 402)]);
        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm());
        $order = Order::where('payment_method', 'kmoney')->firstOrFail();

        $this->get("/area/ordini/{$order->id}")->assertSee('Conferma e paga');
        $this->get('/area/kmoney/ritorno')->assertRedirect("/area/ordini/{$order->id}");
        $this->get("/area/ordini/{$order->id}")->assertSee('Stiamo confermando il pagamento con KMoney')->assertDontSee('Conferma e paga');
    }

    public function test_paying_with_card_does_not_require_a_kmoney_account(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm(['payment_method' => 'transfer']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Order::count());
    }

    // ── «Preferisco confermare ogni volta»: conto collegato senza mandato, col token del conto venditore configurato ──

    private function confirmingEachTime(): array
    {
        config(['lamess.kmoney.api_token' => 'token-venditore']);

        return $this->linkedWithoutMandate();
    }

    public function test_refusing_the_mandate_brings_the_customer_back_to_the_checkout_without_an_error(): void
    {
        [$user, $link] = $this->confirmingEachTime();
        Http::fake();

        $this->actingAs($user)->get('/area/kmoney/ritorno?mandate=denied')
            ->assertRedirect('/area/checkout/pagamento')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->actingAs($user)->withSession(['kmoney_from' => 'profilo'])->get('/area/kmoney/ritorno?mandate=denied')
            ->assertRedirect('/area/profilo#kmoney')
            ->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $this->assertNull($link->refresh()->mandate_uuid);
    }

    public function test_without_the_mandate_confirm_order_opens_a_payment_request_and_sends_the_customer_to_kmoney(): void
    {
        [$user] = $this->confirmingEachTime();
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        Http::fake([
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => []]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
            'kmoney.test/api/v1/payment-requests?*' => Http::response(['data' => []]),
            'kmoney.test/api/v1/payment-requests' => Http::response(['data' => ['uuid' => 'PR-1', 'pay_url' => 'https://kmoney.test/pay/pr-1']], 201),
        ]);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm())
            ->assertRedirect('https://kmoney.test/pay/pr-1')->assertSessionHas('kmoney_order');

        $order = Order::where('payment_method', 'kmoney')->firstOrFail();
        $this->assertSame('pending_payment', $order->status);
        Http::assertSent(fn ($req) => $req->method() === 'POST' && $req->url() === 'https://kmoney.test/api/v1/payment-requests'
            && $req->hasHeader('Authorization', 'Bearer token-venditore')
            && $req['external_reference'] === (string) $order->number
            && $req['amount'] === (int) round((float) $order->kmoney_amount * 100)
            && $req['return_url'] === url('/area/kmoney/ritorno'));
    }

    public function test_without_the_mandate_a_subscription_still_asks_for_the_authorization(): void
    {
        [$user] = $this->confirmingEachTime();
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        Http::fake();

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm(['subscribe' => 'monthly']))
            ->assertSessionHasErrors('order')->assertSessionHas('kmoney_unlinked');

        $this->assertSame(0, Order::count());
        $this->assertSame(0, Subscription::count());
        Http::assertNothingSent();
    }

    /** KMoney finto per il pagamento senza mandato: `$paid` è l'elenco delle richieste incassate, `$opened` conta quelle aperte. */
    private function fakeKMoneyRequests(array &$paid, int &$opened): void
    {
        Http::fake([
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => []]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
            'kmoney.test/api/v1/payment-requests?*' => function () use (&$paid) {
                return Http::response(['data' => $paid]);
            },
            'kmoney.test/api/v1/payment-requests' => function () use (&$opened) {
                $opened++;

                return Http::response(['data' => ['uuid' => 'PR-1', 'pay_url' => 'https://kmoney.test/pay/pr-1']], 201);
            },
        ]);
    }

    private function paidRow(Order $order, string $transfer = 'T-9'): array
    {
        return ['status' => 'paid', 'external_reference' => (string) $order->number, 'amount' => (int) round((float) $order->kmoney_amount * 100), 'transfer_uuid' => $transfer];
    }

    public function test_coming_back_after_paying_on_kmoney_closes_the_order_without_waiting_for_the_webhook(): void
    {
        [$user] = $this->confirmingEachTime();
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        $paid = [];
        $opened = 0;
        $this->fakeKMoneyRequests($paid, $opened);
        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm());
        $order = Order::where('payment_method', 'kmoney')->firstOrFail();

        // Tornato senza aver pagato (la richiesta incassata è di un altro ordine): l'ordine resta in attesa.
        $paid = [['status' => 'paid', 'external_reference' => 'ALTRO', 'amount' => 1, 'transfer_uuid' => 'T-X']];
        $this->withSession(['kmoney_order' => $order->id])->get('/area/kmoney/ritorno')->assertRedirect("/area/ordini/{$order->id}");
        $this->assertSame('pending_payment', $order->refresh()->status);

        $paid = [$this->paidRow($order)];
        $this->withSession(['kmoney_order' => $order->id])->get('/area/kmoney/ritorno')
            ->assertRedirect("/area/ordini/{$order->id}")->assertSessionHas('status', 'Pagamento ricevuto. Grazie!');
        $this->assertContains($order->refresh()->status, Order::PAID_STATES);
    }

    public function test_an_order_already_paid_on_kmoney_never_gets_a_second_payment_request(): void
    {
        [$user] = $this->confirmingEachTime();
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        $paid = [];
        $opened = 0;
        $this->fakeKMoneyRequests($paid, $opened);
        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm());
        $order = Order::where('payment_method', 'kmoney')->firstOrFail();
        $this->assertSame(1, $opened);

        $paid = [$this->paidRow($order)];
        $this->post("/area/ordini/{$order->id}/riprova");

        $this->assertSame(1, $opened, 'nessuna seconda richiesta di pagamento per un ordine già pagato');
        $this->assertContains($order->refresh()->status, Order::PAID_STATES);
    }

    public function test_when_kmoney_does_not_answer_no_payment_request_is_opened_and_the_order_waits(): void
    {
        [$user] = $this->confirmingEachTime();
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        Http::fake([
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => []]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
            'kmoney.test/api/v1/payment-requests?*' => Http::response([], 500),
            'kmoney.test/api/v1/payment-requests' => fn () => $this->fail('senza sapere se è già pagato non si apre una richiesta'),
        ]);

        $res = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm());

        $order = Order::where('payment_method', 'kmoney')->firstOrFail();
        $res->assertRedirect("/area/ordini/{$order->id}")->assertSessionHasErrors('order');
        $this->assertSame('pending_payment', $order->status);
    }

    public function test_the_customer_is_never_sent_to_an_address_that_is_not_kmoney(): void
    {
        [$user] = $this->confirmingEachTime();
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);
        Http::fake([
            'kmoney.test/api/v1/mandates' => Http::response(['mandates' => []]),
            'kmoney.test/api/v1/userinfo' => Http::response(['account' => ['number' => 'KYP0000000001']]),
            'kmoney.test/api/v1/payment-requests?*' => Http::response(['data' => []]),
            'kmoney.test/api/v1/payment-requests' => Http::response(['data' => ['uuid' => 'PR-1', 'pay_url' => 'https://altrove.test/pay/pr-1']], 201),
        ]);

        $res = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->checkoutForm());

        $order = Order::where('payment_method', 'kmoney')->firstOrFail();
        $res->assertRedirect("/area/ordini/{$order->id}")->assertSessionHasErrors('order');
    }

    public function test_checkout_and_profile_tell_the_customer_that_each_payment_is_confirmed_on_kmoney(): void
    {
        [$user] = $this->confirmingEachTime();
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout/pagamento')
            ->assertOk()
            ->assertSee('ti portiamo su KMoney a confermare il pagamento')
            ->assertDontSee('Collega il tuo conto KMoney per continuare');

        $this->get('/area/profilo')->assertOk()->assertSee('hai scelto di confermare ogni pagamento');
    }
}
