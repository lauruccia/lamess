<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Order;
use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Checkout: dati di fatturazione salvati prima di confermare, indirizzo bloccato, modulo conservato quando il cliente esce. */
final class CheckoutBillingLockTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plan();
        $this->shipping('10.00');
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
    }

    private function form(array $over = []): array
    {
        return $over + $this->billing() + ['payment_method' => 'transfer', 'privacy' => '1', 'subscribe' => 'none'];
    }

    public function test_confirm_is_disabled_and_the_order_refused_until_the_billing_data_are_saved(): void
    {
        $user = $this->buyer($this->activeSponsor());
        Profile::where('user_id', $user->id)->update(['address' => null, 'city' => null, 'zip' => null, 'tax_code' => null]);
        $p = $this->product('A', '100.00');

        $html = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('data-billing-missing="1"', $html);
        $this->assertMatchesRegularExpression('/id="confirm-order"[^>]*disabled/', $html);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form())->assertSessionHasErrors('order');
        $this->assertSame(0, Order::count());
    }

    public function test_saving_the_data_stores_them_in_the_profile_and_unlocks_the_confirm_button(): void
    {
        $user = $this->buyer($this->activeSponsor());
        Profile::where('user_id', $user->id)->update(['address' => null, 'city' => null, 'zip' => null, 'tax_code' => null]);
        $p = $this->product('A', '100.00');

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout/dati', $this->form(['payment_method' => 'card', 'subscribe' => 'monthly']))
            ->assertRedirect('/area/checkout')->assertSessionHasNoErrors();

        $this->assertSame('Via Roma 1', $user->profile->refresh()->address);
        $html = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('data-billing-missing="0"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="confirm-order"[^>]*disabled/', $html);
        $this->assertStringContainsString('name="address" value="Via Roma 1" readonly', $html);
    }

    public function test_the_saved_billing_address_cannot_be_changed_from_the_checkout(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form(['address' => 'Via Falsa 99', 'city' => 'Altrove']))->assertRedirect();

        $this->assertSame('Via Roma 1', Order::firstOrFail()->billing['address']);
        $this->assertSame('Via Roma 1', $user->profile->refresh()->address);
    }

    public function test_with_complete_registration_data_all_billing_fields_are_locked_and_the_invoice_uses_them(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');
        $saved = $user->profile->refresh();

        $html = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<select name="type"[^>]*disabled[^>]*hidden/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*readonly[^>]*name="first_name"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*readonly[^>]*name="tax_code"/', $html);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form(['type' => 'company', 'last_name' => 'Verdi', 'tax_code' => 'FALSO', 'company_name' => 'Finta Srl', 'vat_number' => '01234567890']))->assertRedirect();

        $billing = Order::firstOrFail()->billing;
        $this->assertSame($saved->last_name, $billing['last_name']);
        $this->assertSame($saved->tax_code, $billing['tax_code']);
        $this->assertSame('private', $billing['type']);
        $this->assertSame($saved->last_name, $user->profile->refresh()->last_name);
    }

    public function test_incomplete_data_must_be_saved_before_the_order(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $user->profile->update(['tax_code' => null]);
        $p = $this->product('A', '100.00');

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form(['tax_code' => 'RSSMRA80A01H501U']))->assertSessionHasErrors('order');
        $this->assertSame(0, Order::count());
    }

    public function test_leaving_to_connect_kmoney_keeps_the_whole_form_and_it_is_restored_on_return(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])
            ->post('/area/checkout/kmoney', $this->form(['payment_method' => 'kmoney', 'subscribe' => 'monthly', 'ship_to' => 'new', 'ship_address' => 'Via Mare 5', 'ship_city' => 'Scilla', 'ship_zip' => '89058']))
            ->assertRedirect('/area/kmoney/collega');

        $html = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="payment_method" value="kmoney" checked/', $html);
        $this->assertMatchesRegularExpression('/name="subscribe" value="monthly" checked/', $html);
        $this->assertStringContainsString('value="Via Mare 5"', $html);
        $this->assertMatchesRegularExpression('/name="privacy" value="1" form="checkout-form" checked/', $html);
    }

    public function test_nothing_is_preselected_and_the_delivery_choice_sits_in_the_sidebar(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $html = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/name="payment_method"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/name="subscribe"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/id="confirm-order"[^>]*disabled/', $html);
        $this->assertGreaterThan(strpos($html, '<aside class="summary card">'), strpos($html, 'Come vuoi riceverla'));
        $this->assertLessThan(strpos($html, 'id="confirm-order"'), strpos($html, 'Come vuoi riceverla'));
    }

    public function test_the_order_needs_an_explicit_delivery_choice(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');
        $form = $this->form();
        unset($form['subscribe']);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $form)->assertSessionHasErrors('subscribe');
        $this->assertSame(0, Order::count());
    }
}
