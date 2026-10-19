<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\OrderService;
use App\Models\Consultant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Incaricati founder: prezzo founder sui prodotti che lo hanno, punti invariati, tutti gli altri a listino. */
final class FounderPricesTest extends TestCase
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

    private function founder(): Consultant
    {
        $c = $this->activeSponsor();
        $c->update(['user_id' => $this->buyer()->id, 'is_founder' => true]);

        return $c->refresh();
    }

    public function test_founder_pays_the_founder_price_and_keeps_the_same_points(): void
    {
        $p = $this->product('Box', '100.00', ['founder_net_price' => '80.00', 'pv' => '100']);
        $founder = $this->founder();
        $regular = $this->buyer($founder);

        $this->assertSame('122.00', $p->grossPriceFor($regular));
        $this->assertSame('97.60', $p->grossPriceFor($founder->user));

        $mine = app(OrderService::class)->place($founder->user, [$p->id => 1], 'IT', 'transfer', $this->billing());
        $other = app(OrderService::class)->place($regular, [$p->id => 1], 'IT', 'transfer', $this->billing());

        $this->assertSame('80.00', $mine->subtotal_net);
        $this->assertSame('100.00', $other->subtotal_net);
        $this->assertSame($mine->pv_total, $other->pv_total, 'cambia solo il prezzo, non i punti');
    }

    public function test_product_without_founder_price_and_removed_status_use_list_price(): void
    {
        $with = $this->product('Box', '100.00', ['founder_net_price' => '80.00']);
        $without = $this->product('Altro', '50.00');
        $founder = $this->founder();

        $this->assertSame('61.00', $without->grossPriceFor($founder->user));

        $founder->update(['is_founder' => false]);
        $this->assertSame('122.00', $with->grossPriceFor($founder->user->refresh()));
    }

    public function test_shop_page_shows_the_founder_price_only_to_founders(): void
    {
        $this->product('Box Founder', '100.00', ['founder_net_price' => '80.00']);
        $founder = $this->founder();

        $this->actingAs($founder->user)->get('/negozio')->assertOk()->assertSee('97,60');
        $this->actingAs($this->buyer())->get('/negozio')->assertOk()->assertSee('122,00')->assertDontSee('97,60');
    }
}
