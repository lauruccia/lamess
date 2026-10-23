<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Customers\ShippingAddressBook;
use App\Models\Order;
use App\Models\Profile;
use App\Models\ShippingRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Nazione nel checkout: Italia di default, spedizione estero con tariffa propria, nazioni non aperte ignorate. */
final class CheckoutCountryTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.shop_countries' => ['IT', 'DE']]);
        $this->plan();
        $this->shipping('10.00');
        ShippingRate::create(['country_code' => 'DE', 'amount_net' => '25.00', 'vat_rate' => '22.00']);
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
    }

    private function form(array $over = []): array
    {
        return $over + $this->billing() + ['payment_method' => 'transfer', 'privacy' => '1', 'subscribe' => 'none'];
    }

    public function test_checkout_shows_the_country_field_with_italy_selected(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $html = $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->getContent();

        $this->assertStringContainsString('name="country"', $html);
        $this->assertMatchesRegularExpression('/<option value="IT" selected>Italia<\/option>/', $html);
        $this->assertStringContainsString('<option value="DE" >Germania</option>', $html);
    }

    public function test_changing_country_keeps_the_form_and_recalculates_shipping(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])
            ->post('/area/checkout/nazione', ['country' => 'DE', 'ship_to' => 'billing', 'city' => 'Berlino'])
            ->assertRedirect('/area/checkout')
            ->assertSessionHas('checkout_country', 'DE')
            ->assertSessionHasInput('city', 'Berlino');
    }

    public function test_order_to_germany_uses_the_german_rate_and_country(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');
        // L'indirizzo di fatturazione è bloccato nel checkout: spedire in Germania «come la fatturazione» vale solo se il profilo ha la Germania.
        Profile::where('user_id', $user->id)->update(['country_code' => 'DE']);

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])
            ->post('/area/checkout', $this->form(['country' => 'DE']))->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame('DE', $order->country_code);
        $this->assertSame('25.00', $order->shipping_net);
    }

    public function test_default_is_italy_and_closed_countries_fall_back_to_italy(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00');

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout/nazione', ['country' => 'FR'])
            ->assertSessionHas('checkout_country', 'IT');

        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->post('/area/checkout', $this->form())->assertRedirect();
        $this->assertSame('IT', Order::firstOrFail()->country_code);
    }

    public function test_saved_address_keeps_its_country(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $book = app(ShippingAddressBook::class);

        $de = $book->add($user, ['address' => 'Hauptstr. 1', 'city' => 'Berlin', 'zip' => '10115', 'country_code' => 'DE']);
        $it = $book->add($user, ['address' => 'Via Roma 1', 'city' => 'Reggio Calabria', 'zip' => '89100']);
        $xx = $book->add($user, ['address' => 'Rue 1', 'city' => 'Paris', 'zip' => '75001', 'country_code' => 'FR']);

        $this->assertSame('DE', $de->country_code);
        $this->assertSame('IT', $it->country_code);
        $this->assertSame('IT', $xx->country_code, 'nazione non aperta = Italia');
        $this->assertStringContainsString('Germania', $de->oneLine());
    }
}
