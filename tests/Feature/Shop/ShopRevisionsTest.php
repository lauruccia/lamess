<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\PaymentResult;
use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Carrello che resta finché l'ordine non è pagato, % KMoney nel negozio, IVA inclusa con la sola % sull'ordine. */
final class ShopRevisionsTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.kmoney_driver' => 'fake', 'lamess.payments.kmoney' => true]);
        $this->plan();
        $this->shipping('5.00');
    }

    /** @return array<string,mixed> */
    private function form(array $over = []): array
    {
        return $over + $this->billing() + ['payment_method' => 'card', 'privacy' => '1', 'subscribe' => 'none'];
    }

    private function card(bool $works): object
    {
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
        $card->works = $works;
        $this->app->instance(PaymentProvider::class, $card);

        return $card;
    }

    public function test_the_cart_survives_a_card_order_that_is_not_paid(): void
    {
        $this->card(false);
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $this->actingAs($u)->withSession(['cart' => [$p->id => 2]])->post('/area/checkout', $this->form())->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame('pending_payment', $order->status);

        $this->get('/carrello')->assertOk()->assertSee('Procedi al checkout')->assertSee('in attesa di pagamento');
        $this->assertSame([$p->id => 2], session('cart'));
        $this->get('/area/checkout')->assertOk()->assertSee('Conferma ordine');
    }

    public function test_reordering_closes_the_unpaid_order_and_the_cart_empties_when_the_new_one_is_paid(): void
    {
        $card = $this->card(false);
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form());
        $first = Order::firstOrFail();

        $card->works = true;
        $this->post('/area/checkout', $this->form())->assertRedirect();
        $this->assertSame('cancelled', $first->refresh()->status);
        $second = Order::where('id', '!=', $first->id)->firstOrFail();
        $this->assertSame('paid', $second->status);

        $this->get('/carrello')->assertOk()->assertSee('Il carrello è vuoto');
    }

    public function test_the_cart_is_emptied_when_the_pending_order_is_paid_later(): void
    {
        $card = $this->card(false);
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form());
        $order = Order::firstOrFail();
        $card->works = true;
        $this->post("/area/ordini/{$order->id}/riprova")->assertRedirect();
        $this->assertSame('paid', $order->refresh()->status);

        $this->get('/carrello')->assertOk()->assertSee('Il carrello è vuoto');
    }

    public function test_a_bank_transfer_order_empties_the_cart_at_once(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form(['payment_method' => 'transfer']))->assertRedirect();
        $this->get('/carrello')->assertOk()->assertSee('Il carrello è vuoto');
    }

    public function test_product_page_and_lists_show_the_kmoney_percent_without_fino_al(): void
    {
        $this->product('A', '100.00', ['name' => 'Crema Cinquanta', 'kmoney_percent' => 50]);
        $this->product('B', '100.00', ['name' => 'Crema Solo Euro', 'kmoney_percent' => 0]);

        $this->get('/negozio/crema-cinquanta')->assertOk()->assertSee('50% in KMoney')->assertDontSee('Fino al 50%');
        $this->get('/negozio')->assertOk()->assertSee('50% in KMoney');
    }

    public function test_shop_list_can_be_filtered_and_sorted_by_kmoney_percent(): void
    {
        $this->product('A', '100.00', ['name' => 'Crema Cinquanta', 'kmoney_percent' => 50]);
        $this->product('B', '100.00', ['name' => 'Crema Solo Euro', 'kmoney_percent' => 0]);
        $this->product('C', '100.00', ['name' => 'Crema Tutto', 'kmoney_percent' => 100]);

        $this->get('/negozio?kmoney=1')->assertOk()->assertSee('Crema Cinquanta')->assertSee('Crema Tutto')->assertDontSee('Crema Solo Euro');
        $this->get('/negozio?kmoney=100')->assertOk()->assertSee('Crema Tutto')->assertDontSee('Crema Cinquanta')->assertDontSee('Crema Solo Euro');
        $this->get('/negozio')->assertOk()->assertSee('Crema Solo Euro')->assertSee('Filtro KMoney', false);
        $this->get('/negozio?ordina=kmoney')->assertOk()->assertSeeInOrder(['Crema Tutto', 'Crema Cinquanta', 'Crema Solo Euro']);
    }

    public function test_checkout_shows_the_kmoney_amount_per_product_in_the_summary(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 50]);

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->assertSee('Da pagare in KMoney')->assertSee('Da pagare in euro')->assertDontSee('Paga al 50%')->assertDontSee('Risparmia fino al');
    }

    public function test_order_page_shows_gross_amounts_with_only_the_vat_rate_indicated(): void
    {
        $this->card(true);
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form());
        $order = Order::firstOrFail();

        $this->get("/area/ordini/{$order->id}")->assertOk()
            ->assertSee('€ 122,00')            // riga prodotto IVA inclusa
            ->assertSee('€ 6,10')              // spedizione IVA inclusa
            ->assertSee('€ 128,10')            // totale
            ->assertSee('IVA 22% inclusa')
            ->assertDontSee('di cui IVA')
            ->assertSeeInOrder(['Totale', 'IVA 22% inclusa']);
    }

    public function test_a_cart_fully_payable_in_kmoney_pays_the_shipping_in_kmoney_too(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form(['payment_method' => 'kmoney']));
        $order = Order::firstOrFail();

        $this->assertSame('128.10', $order->kmoney_amount, 'prodotto e spedizione, IVA inclusa');
        $this->assertNull($order->kmoney_rest_method, 'nessun resto da pagare con carta o bonifico');
    }

    public function test_a_partly_kmoney_cart_leaves_the_shipping_in_the_rest(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 50]);

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form(['payment_method' => 'kmoney', 'kmoney_rest' => 'card']));
        $order = Order::firstOrFail();

        $this->assertSame('61.00', $order->kmoney_amount);
        $this->assertSame('card', $order->kmoney_rest_method);
    }

    public function test_with_kmoney_the_rest_is_chosen_after_the_kmoney_share_is_charged(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 50]);

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form(['payment_method' => 'kmoney']))->assertSessionHasNoErrors();
        $order = Order::firstOrFail();
        $this->assertSame('61.00', $order->kmoney_amount);
        $this->assertNull($order->kmoney_rest_method, 'il resto non si sceglie nel checkout');
        $this->assertSame(1, $order->payments()->count(), 'per ora esiste solo il pagamento KMoney');
        $this->assertSame('paid', $order->payments()->firstOrFail()->status, '«Conferma ordine» è già la conferma: la quota KMoney si addebita subito, in un clic');

        // prima la quota KMoney: finché non è incassata il resto non si può scegliere
        $order->payments()->where('method', 'kmoney')->update(['status' => 'pending']);
        $this->post("/area/ordini/{$order->id}/resto", ['kmoney_rest' => 'card'])->assertSessionHasErrors('order');
        $this->assertNull($order->refresh()->kmoney_rest_method);

        $order->payments()->where('method', 'kmoney')->update(['status' => 'paid']);
        $this->get("/area/ordini/{$order->id}")->assertOk()->assertSee('come vuoi pagare il resto');
        $this->post("/area/ordini/{$order->id}/resto", ['kmoney_rest' => 'transfer']);
        $order->refresh();
        $this->assertSame('transfer', $order->kmoney_rest_method);
        $this->assertSame('67.10', $order->payments()->where('method', 'transfer')->firstOrFail()->amount);
    }

    public function test_the_rest_cannot_be_paid_with_a_method_that_is_switched_off(): void
    {
        config(['lamess.payments.transfer' => false]);
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 50]);

        $this->actingAs($u)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form(['payment_method' => 'kmoney']));
        $order = Order::firstOrFail();
        $order->payments()->where('method', 'kmoney')->update(['status' => 'paid']);

        $this->get("/area/ordini/{$order->id}")->assertOk()->assertDontSee('value="transfer"', false);
        $this->post("/area/ordini/{$order->id}/resto", ['kmoney_rest' => 'transfer'])->assertSessionHasErrors('order');
        $this->assertNull($order->refresh()->kmoney_rest_method);
    }

    public function test_checkout_shows_free_shipping_in_the_subscription_summary_and_no_rest_when_all_in_kmoney(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $html = $this->actingAs($u)->withSession(['cart' => [$p->id => 1], '_old_input' => ['subscribe' => 'monthly']])->get('/area/checkout')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<div data-sum-sub\s*>.*?Gratuita.*?€ 122,00/su', $html, 'con l\'abbonamento la spedizione è gratuita e il totale è solo il prodotto');
        $this->assertMatchesRegularExpression('/<div data-sum-single\s+hidden\s*>/', $html, 'l\'abbonamento scelto nasconde il riepilogo dell\'ordine singolo');
        $this->assertStringNotContainsString('data-km-rest', $html, 'con tutto in KMoney non c\'è nessun resto da scegliere');
    }

    public function test_the_subscription_order_never_charges_shipping(): void
    {
        $u = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $sub = app(SubscriptionService::class)->subscribe($u, [$p->id => 1], 'IT', $this->billing(), 0);

        $this->assertTrue((bool) $sub->free_shipping);
        $this->assertSame('0.00', Order::where('subscription_id', $sub->id)->firstOrFail()->shipping_net);
    }
}
