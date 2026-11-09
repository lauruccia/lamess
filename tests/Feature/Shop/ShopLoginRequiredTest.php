<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Negozio «solo dopo l'accesso»: le pagine dei prodotti restano visibili (e indicizzabili) ma senza prezzi e senza carrello. */
final class ShopLoginRequiredTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lamess.shop_login_required' => true]);
    }

    public function test_guests_see_the_catalog_without_prices_or_cart_buttons(): void
    {
        $p = $this->product('A', '100.00', ['name' => 'Crema viso']);

        $this->get('/negozio')->assertOk()->assertSee('Crema viso')->assertDontSee('122,00')->assertDontSee('Aggiungi al carrello')->assertSee('Accedi per acquistare');
        $this->get('/negozio/'.$p->slug)->assertOk()->assertSee('Crema viso')->assertDontSee('122,00')->assertDontSee('"price"', false)
            ->assertDontSee('Aggiungi al carrello')->assertSee('Accedi per acquistare');
    }

    public function test_price_filters_and_price_sorting_are_ignored_for_guests(): void
    {
        $this->product('A', '100.00', ['name' => 'Crema viso']);

        $this->get('/negozio?prezzo_min=500')->assertOk()->assertSee('Crema viso');
        $this->get('/negozio?ordina=prezzo-asc')->assertOk()->assertDontSee('Prezzo: dal più basso');
    }

    public function test_the_cart_needs_a_login(): void
    {
        $p = $this->product('A', '100.00');

        $this->get('/carrello')->assertRedirect('/accedi');
        $this->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1])->assertRedirect('/accedi');
        $this->postJson('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1])->assertStatus(401);
        $this->getJson('/carrello/mini')->assertStatus(401);
        $this->assertSame([], session('cart', []));
    }

    public function test_logged_in_users_see_prices_and_buy_as_before(): void
    {
        $p = $this->product('A', '100.00', ['name' => 'Crema viso']);
        $this->actingAs(User::factory()->create());

        $this->get('/negozio')->assertOk()->assertSee('122,00')->assertSee('href="/carrello"', false);
        $this->get('/negozio/'.$p->slug)->assertOk()->assertSee('122,00')->assertSee('Aggiungi al carrello');
        $this->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1])->assertRedirect('/carrello');
    }

    public function test_switching_the_setting_off_shows_prices_to_everyone(): void
    {
        config(['lamess.shop_login_required' => false]);
        $p = $this->product('A', '100.00', ['name' => 'Crema viso']);

        $this->get('/negozio/'.$p->slug)->assertOk()->assertSee('122,00');
        $this->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1])->assertRedirect('/carrello');
    }

    public function test_login_returns_to_the_page_the_guest_came_from_but_only_inside_the_site(): void
    {
        $this->get('/accedi?dopo=/negozio/crema-viso')->assertOk();
        $this->assertStringEndsWith('/negozio/crema-viso', (string) session('url.intended'));

        $this->flushSession();
        $this->get('/accedi?dopo=//evil.example')->assertOk();
        $this->assertNull(session('url.intended'));
    }
}
