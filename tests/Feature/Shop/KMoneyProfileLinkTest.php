<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\KMoneyLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Collegamento del conto KMoney dal profilo e rimando dal checkout (driver «fake» = collegamento simulato). */
final class KMoneyProfileLinkTest extends TestCase
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

    public function test_profile_offers_to_connect_and_checkout_links_to_the_profile_when_not_linked(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $this->actingAs($user)->get('/area/profilo')->assertOk()->assertSee('Conto KMoney')->assertSee('/area/kmoney/collega?da=profilo', false);
        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->assertSee('/area/profilo#kmoney', false);
    }

    public function test_connecting_from_the_profile_returns_to_the_profile_and_checkout_shows_it_linked(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $p = $this->product('A', '100.00', ['kmoney_percent' => 100]);

        $this->actingAs($user)->get('/area/kmoney/collega?da=profilo')->assertRedirect('/area/profilo#kmoney');
        $this->assertTrue(KMoneyLink::where('user_id', $user->id)->whereNotNull('mandate_uuid')->exists());

        $this->actingAs($user)->get('/area/profilo')->assertOk()->assertSee('collegato')->assertSee('Scollega il conto');
        $this->actingAs($user)->withSession(['cart' => [$p->id => 1]])->get('/area/checkout')->assertOk()->assertSee('Conto KMoney collegato.');
    }

    public function test_user_can_disconnect_the_account(): void
    {
        $user = $this->buyer($this->activeSponsor());
        $this->actingAs($user)->get('/area/kmoney/collega?da=profilo');

        $this->actingAs($user)->post('/area/kmoney/scollega')->assertRedirect('/area/profilo#kmoney');
        $this->assertFalse(KMoneyLink::where('user_id', $user->id)->exists());
    }

    public function test_profile_says_kmoney_is_unavailable_when_the_method_is_off(): void
    {
        config(['lamess.kmoney_driver' => 'off']);
        $user = $this->buyer($this->activeSponsor());

        $this->actingAs($user)->get('/area/profilo')->assertOk()->assertSee('non è attivo al momento')->assertDontSee('/area/kmoney/collega?da=profilo', false);
    }
}
