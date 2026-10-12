<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Models\Discount;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class VariantsTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    /** Integratore in due formati: 50 bustine a 10 € e 100 bustine a 18 € (netti). */
    private function withVariants(): Product
    {
        $parent = $this->product('INT', '10.00', ['name' => 'Integratore', 'options' => [['name' => 'Formato', 'values' => ['50 bustine', '100 bustine']]]]);
        foreach ([['50 bustine', '10.00', 4], ['100 bustine', '18.00', null]] as $i => [$label, $net, $stock]) {
            Product::create(['parent_id' => $parent->id, 'variant_options' => ['Formato' => $label], 'net_price' => $net, 'stock_qty' => $stock, 'sort_order' => $i, 'sku' => '']);
        }

        return $parent->refresh();
    }

    public function test_variant_is_a_product_named_after_its_parent_and_inherits_its_settings(): void
    {
        $this->plan();
        $parent = $this->product('INT', '10.00', ['name' => 'Integratore', 'vat_rate' => '10.00', 'commissionable_pct' => '80.00']);
        $v = Product::create(['parent_id' => $parent->id, 'variant_options' => ['Formato' => '1 kg'], 'net_price' => '30.00']);

        $this->assertSame('Integratore — 1 kg', $v->name);
        $this->assertSame('1 kg', $v->variant_label);
        $this->assertSame('INT-1-KG', $v->sku);
        $this->assertSame('10.00', (string) $v->vat_rate);
        $this->assertSame('80.00', (string) $v->commissionable_pct);

        $parent->update(['name' => 'Integratore Plus']);
        $this->assertSame('Integratore Plus — 1 kg', $v->fresh()->name);
    }

    public function test_parent_follows_the_lowest_variant_and_is_not_buyable_on_its_own(): void
    {
        $this->plan();
        $parent = $this->withVariants();

        $this->assertTrue($parent->hasVariants());
        $this->assertSame('10.00000000', (string) $parent->net_price);
        $this->assertSame(['12.20', '21.96'], $parent->variantPriceRange());
        $this->assertFalse(Product::buyable()->whereKey($parent->id)->exists());
        $this->assertSame(2, Product::buyable()->whereNotNull('parent_id')->count());
        $this->assertSame([$parent->id], Product::onShelf()->pluck('id')->all());
    }

    public function test_parent_disappears_when_no_variant_is_published_and_variants_follow_the_parent_visibility(): void
    {
        $this->plan();
        $parent = $this->withVariants();
        $parent->variants->each->update(['active' => false]);
        $this->assertSame(0, Product::onShelf()->count());

        $parent->variants->each->update(['active' => true]);
        $parent->update(['active' => false]);
        $this->assertSame(0, Product::buyable()->count());
    }

    public function test_product_page_shows_the_options_and_a_variant_link_opens_the_parent_page(): void
    {
        $this->plan();
        $parent = $this->withVariants();
        $second = $parent->variants[1];

        $this->get('/negozio/'.$parent->slug)->assertOk()->assertSee('Formato')->assertSee('50 bustine')->assertSee('100 bustine')->assertSee('variants-data', false);
        $this->get('/negozio/'.$parent->slug.'?variante='.$second->id)->assertOk()->assertSee('21,96');
        $this->get('/negozio')->assertOk()->assertSee('Integratore')->assertSee('da');
        $this->get('/negozio?q=100+bustine')->assertOk()->assertSee('Integratore');
    }

    public function test_cart_accepts_a_variant_but_asks_to_choose_for_the_parent(): void
    {
        $this->plan();
        $parent = $this->withVariants();
        $v = $parent->variants[1];

        $this->postJson('/carrello/aggiungi', ['product_id' => $parent->id, 'qty' => 1])->assertStatus(422)->assertJsonPath('message', 'Scegli prima una variante (formato, taglia, colore…).');
        $res = $this->postJson('/carrello/aggiungi', ['product_id' => $v->id, 'qty' => 2])->assertOk()->assertJsonPath('count', 2);
        $this->assertStringContainsString('Integratore — 100 bustine', (string) $res->json('html'));
    }

    public function test_variant_stock_is_checked_and_decremented_per_variant(): void
    {
        Notification::fake();
        $this->plan();
        $parent = $this->withVariants();
        [$small, $big] = [$parent->variants[0], $parent->variants[1]];
        $buyer = $this->buyer($this->activeSponsor());

        try {
            app(OrderService::class)->place($buyer, [$small->id => 5], 'IT', 'transfer', $this->billing());
            $this->fail('Doveva rifiutare 5 pezzi con giacenza 4.');
        } catch (OrderException $e) {
            $this->assertStringContainsString('ne restano solo 4', $e->getMessage());
        }

        $order = app(OrderService::class)->place($buyer, [$small->id => 3, $big->id => 2], 'IT', 'transfer', $this->billing());
        $this->assertSame(1, $small->fresh()->stock_qty);
        $this->assertNull($big->fresh()->stock_qty);
        $this->assertEqualsCanonicalizing(['Integratore — 50 bustine', 'Integratore — 100 bustine'], $order->items->pluck('name')->all());

        app(OrderService::class)->cancel($order);
        $this->assertSame(4, $small->fresh()->stock_qty);
    }

    public function test_ordering_the_parent_directly_is_refused_and_points_follow_each_variant_price(): void
    {
        Notification::fake();
        $this->plan();
        $parent = $this->withVariants();
        $buyer = $this->buyer($this->activeSponsor());

        try {
            app(OrderService::class)->place($buyer, [$parent->id => 1], 'IT', 'transfer', $this->billing());
            $this->fail('Il prodotto con varianti non si ordina direttamente.');
        } catch (OrderException $e) {
            $this->assertStringContainsString('varianti', $e->getMessage());
        }

        $this->assertGreaterThan((float) $parent->variants[0]->pvUnit(), (float) $parent->variants[1]->pvUnit());
    }

    public function test_discount_on_a_parent_product_applies_to_its_variants(): void
    {
        Notification::fake();
        $this->plan();
        $parent = $this->withVariants();
        Discount::create(['name' => 'Offerta', 'type' => 'percent', 'value' => '10', 'applies_to' => 'products', 'product_ids' => [$parent->id], 'active' => true]);
        $buyer = $this->buyer($this->activeSponsor());

        $order = app(OrderService::class)->place($buyer, [$parent->variants[1]->id => 1], 'IT', 'transfer', $this->billing());

        $this->assertSame('16.20', (string) $order->subtotal_net);
    }

    public function test_parent_with_a_sold_variant_cannot_be_deleted_but_an_unsold_one_goes_away_with_its_variants(): void
    {
        Notification::fake();
        $this->plan();
        $sold = $this->withVariants();
        $buyer = $this->buyer($this->activeSponsor());
        app(OrderService::class)->place($buyer, [$sold->variants[1]->id => 1], 'IT', 'transfer', $this->billing());
        $this->assertFalse($sold->isDeletable());

        $free = $this->product('LIB', '5.00');
        Product::create(['parent_id' => $free->id, 'variant_options' => ['Taglia' => 'M'], 'net_price' => '5.00']);
        $this->assertTrue($free->isDeletable());
        $free->delete();
        $this->assertSame(0, Product::where('parent_id', $free->id)->count());
    }

    public function test_variant_photo_wins_and_falls_back_to_the_parent_photo(): void
    {
        $this->plan();
        $parent = $this->withVariants();
        $parent->update(['images' => ['shop/products/main.png']]);
        [$a, $b] = [$parent->variants[0], $parent->variants[1]];
        $a->update(['images' => ['shop/products/limone.png']]);

        $this->assertStringEndsWith('/media/shop/products/limone.png', (string) $a->fresh()->coverUrl());
        $this->assertStringEndsWith('/media/shop/products/main.png', (string) $b->fresh()->coverUrl());
        $this->get('/negozio/'.$parent->slug.'?variante='.$a->id)->assertOk()->assertSee('limone.png');
    }
}
