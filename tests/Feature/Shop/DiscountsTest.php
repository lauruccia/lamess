<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\CartPricer;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ReturnService;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class DiscountsTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function svc(): OrderService
    {
        return app(OrderService::class);
    }

    private function discount(array $over = []): Discount
    {
        return Discount::create($over + ['name' => 'Sconto', 'type' => 'percent', 'value' => '10', 'active' => true]);
    }

    /** @param  array<int,int>  $lines */
    private function price(array $lines, ?object $user = null, array $codes = [], bool $waived = false)
    {
        $entries = [];
        foreach ($lines as $pid => $qty) {
            $entries[(string) $pid] = ['product' => Product::findOrFail($pid), 'qty' => $qty];
        }

        return app(CartPricer::class)->price($entries, $user, $codes, null, 'IT', $waived);
    }

    public function test_percent_code_lowers_unit_price_and_is_frozen_on_the_order(): void
    {
        Notification::fake();
        $this->plan();
        $p = $this->product('A', '100.00');
        $this->discount(['code' => 'ciao10', 'name' => 'Benvenuto']);
        $user = $this->buyer($this->activeSponsor());

        $o = $this->svc()->place($user, [$p->id => 2], 'IT', 'transfer', $this->billing(), discountCodes: ['ciao10']);

        $this->assertSame('180.00', $o->subtotal_net);
        $this->assertSame('219.60', $o->total);
        $this->assertSame('20.00', $o->discount_net);
        $item = $o->items->first();
        $this->assertSame('90.00000000', (string) $item->unit_net);
        $this->assertSame('100.00000000', (string) $item->list_unit_net);
        $this->assertSame('CIAO10', $o->discounts[0]['code']);
        $this->assertSame(1, DiscountRedemption::where('order_id', $o->id)->count());
    }

    public function test_codes_are_case_and_space_insensitive_and_unknown_code_is_refused(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $this->discount(['code' => 'CIAO10']);
        $user = $this->buyer($this->activeSponsor());

        $this->assertCount(1, $this->price([$p->id => 1], $user, [' ciao 10 '])->applied);
        $this->assertSame('Codice non valido.', $this->price([$p->id => 1], $user, ['XXX'])->messages['XXX']);
        $this->expectException(OrderException::class);
        $this->svc()->place($user, [$p->id => 1], 'IT', 'transfer', $this->billing(), discountCodes: ['XXX']);
    }

    public function test_fixed_amount_is_vat_inclusive_and_split_across_lines(): void
    {
        $a = $this->product('A', '100.00');
        $b = $this->product('B', '100.00', ['vat_rate' => '10.00']);
        $this->discount(['code' => 'DIECI', 'type' => 'fixed', 'value' => '20.00']);

        $cart = $this->price([$a->id => 1, $b->id => 1], null, ['DIECI']);

        $this->assertSame('20.00', (string) $cart->discountGross());
        $this->assertSame('212.00', (string) $cart->itemsGross()); // 122 + 110 - 20
    }

    public function test_fixed_amount_never_exceeds_the_cart(): void
    {
        $a = $this->product('A', '10.00');
        $this->discount(['code' => 'TANTO', 'type' => 'fixed', 'value' => '500']);

        $cart = $this->price([$a->id => 1], null, ['TANTO']);

        $this->assertSame('0.00', (string) $cart->itemsGross());
        $this->assertSame('0.00', $cart->totals()['total']);
    }

    public function test_tier_prices(): void
    {
        $p = $this->product('A', '100.00');
        $this->discount(['name' => 'Scaglioni', 'type' => 'tiered', 'tiers' => [['min_qty' => 3, 'percent' => 10], ['min_qty' => 6, 'percent' => 15]], 'value' => 0]);

        $this->assertSame('90.00000000', (string) $this->price([$p->id => 3])->lines[(string) $p->id]->unitNet);
        $this->assertSame('85.00000000', (string) $this->price([$p->id => 6])->lines[(string) $p->id]->unitNet);
        $this->assertSame('100.00000000', (string) $this->price([$p->id => 2])->lines[(string) $p->id]->unitNet);
    }

    public function test_free_shipping_threshold_hint_and_application(): void
    {
        $this->shipping('5.00');
        $p = $this->product('A', '50.00');
        $this->discount(['name' => 'Spedizione omaggio', 'type' => 'free_shipping', 'value' => 0, 'min_subtotal' => '100.00']);

        $low = $this->price([$p->id => 1]);
        $this->assertSame('5.00', (string) $low->shippingNet);
        $this->assertSame('39.00', $low->freeShippingHint['missing']); // 100 - 61

        $high = $this->price([$p->id => 2]); // 122 € IVA inclusa
        $this->assertSame('0.00', (string) $high->shippingNet);
        $this->assertNull($high->freeShippingHint);
        $this->assertSame('5.00', (string) $high->shippingDiscountNet());
    }

    public function test_conditions_scope_and_limits_explain_why_a_code_fails(): void
    {
        $this->plan();
        $cat = ProductCategory::create(['name' => 'Viso']);
        $in = $this->product('A', '100.00', ['category_id' => $cat->id]);
        $out = $this->product('B', '100.00');
        $user = $this->buyer($this->activeSponsor());

        $this->discount(['code' => 'VISO', 'applies_to' => 'categories', 'category_ids' => [$cat->id]]);
        $cart = $this->price([$in->id => 1, $out->id => 1], $user, ['VISO']);
        $this->assertSame('90.00000000', (string) $cart->lines[(string) $in->id]->unitNet);
        $this->assertSame('100.00000000', (string) $cart->lines[(string) $out->id]->unitNet);
        $this->assertStringContainsString('non si applica', $this->price([$out->id => 1], $user, ['VISO'])->messages['VISO']);

        $this->discount(['code' => 'MIN', 'min_qty' => 3]);
        $this->assertStringContainsString('almeno 3', $this->price([$out->id => 1], $user, ['MIN'])->messages['MIN']);

        $this->discount(['code' => 'SPESA', 'min_subtotal' => '300.00']);
        $this->assertStringContainsString('Spesa minima', $this->price([$out->id => 1], $user, ['SPESA'])->messages['SPESA']);

        $this->discount(['code' => 'FINITO', 'ends_at' => now()->subDay()]);
        $this->assertSame('Codice scaduto.', $this->price([$out->id => 1], $user, ['FINITO'])->messages['FINITO']);

        $this->discount(['code' => 'DOMANI', 'starts_at' => now()->addDay()]);
        $this->assertStringContainsString('sarà valido dal', $this->price([$out->id => 1], $user, ['DOMANI'])->messages['DOMANI']);

        $this->discount(['code' => 'OFF', 'active' => false]);
        $this->assertSame('Codice non più attivo.', $this->price([$out->id => 1], $user, ['OFF'])->messages['OFF']);
    }

    public function test_usage_limits_total_and_per_user_and_cancel_releases_them(): void
    {
        Notification::fake();
        $this->plan();
        $p = $this->product('A', '100.00');
        $this->discount(['code' => 'UNA', 'usage_limit' => 1, 'usage_limit_per_user' => 1]);
        $sponsor = $this->activeSponsor();
        $first = $this->buyer($sponsor);
        $second = $this->buyer($sponsor);

        $order = $this->svc()->place($first, [$p->id => 1], 'IT', 'transfer', $this->billing(), discountCodes: ['UNA']);
        $this->assertSame('Codice esaurito.', $this->price([$p->id => 1], $second, ['UNA'])->messages['UNA']);

        $this->svc()->cancel($order);
        $this->assertSame(0, DiscountRedemption::count());
        $this->assertCount(1, $this->price([$p->id => 1], $second, ['UNA'])->applied);
    }

    public function test_per_user_limit_requires_login(): void
    {
        $p = $this->product('A', '100.00');
        $this->discount(['code' => 'ACCEDI', 'usage_limit_per_user' => 1]);

        $this->assertSame('Accedi per usare questo codice.', $this->price([$p->id => 1], null, ['ACCEDI'])->messages['ACCEDI']);
    }

    public function test_first_order_and_audience_rules(): void
    {
        Notification::fake();
        $this->plan();
        $p = $this->product('A', '100.00');
        $this->discount(['code' => 'PRIMO', 'audience' => 'first_order']);
        $this->discount(['code' => 'CONS', 'audience' => 'consultants']);
        $user = $this->buyer($this->activeSponsor());

        $this->assertCount(1, $this->price([$p->id => 1], $user, ['PRIMO'])->applied);
        $this->assertSame('Codice riservato agli incaricati.', $this->price([$p->id => 1], $user, ['CONS'])->messages['CONS']);

        $this->svc()->place($user, [$p->id => 1], 'IT', 'transfer', $this->billing());
        $this->assertSame('Codice valido solo sul primo ordine.', $this->price([$p->id => 1], $user, ['PRIMO'])->messages['PRIMO']);
    }

    public function test_best_discount_wins_unless_both_are_combinable(): void
    {
        $p = $this->product('A', '100.00');
        $this->discount(['name' => 'Auto 20', 'value' => '20']);
        $this->discount(['name' => 'Codice 10', 'code' => 'DIECI', 'value' => '10']);

        $cart = $this->price([$p->id => 1], null, ['DIECI']);
        $this->assertCount(1, $cart->applied);
        $this->assertSame('Auto 20', $cart->applied[0]->discount->name);
        $this->assertStringContainsString('cumulabile', $cart->messages['DIECI']);

        Discount::query()->update(['combinable' => true]);
        $stacked = $this->price([$p->id => 1], null, ['DIECI']);
        $this->assertCount(2, $stacked->applied);
        $this->assertSame('72.00000000', (string) $stacked->lines[(string) $p->id]->unitNet); // 100 -20% -10%
    }

    public function test_pv_follow_the_catalog_unless_the_product_or_policy_scales_them(): void
    {
        Notification::fake();
        $this->plan();
        $fixed = $this->product('A', '123.00', ['pv_discount_mode' => 'fixed']);
        $prop = $this->product('B', '123.00', ['pv_discount_mode' => 'proportional']);
        $inherit = $this->product('C', '123.00'); // politica del piano: «catalog» di default
        $this->discount(['code' => 'META', 'value' => '50']);
        $user = $this->buyer($this->activeSponsor());

        $o = $this->svc()->place($user, [$fixed->id => 1, $prop->id => 1, $inherit->id => 1], 'IT', 'transfer', $this->billing(), discountCodes: ['META']);

        $pv = $o->items->pluck('pv_unit_frozen', 'sku');
        $this->assertSame('100.00', $pv['A']);
        $this->assertSame('50.00', $pv['B']);
        $this->assertSame('100.00', $pv['C']);
        $this->assertSame('250.00', $o->pv_total);
    }

    public function test_plan_policy_proportional_scales_inheriting_products(): void
    {
        Notification::fake();
        $this->plan([], ['discount_pv_policy' => 'proportional']);
        $p = $this->product('A', '123.00');
        $this->discount(['code' => 'QUARTO', 'value' => '25']);
        $user = $this->buyer($this->activeSponsor());

        $o = $this->svc()->place($user, [$p->id => 2], 'IT', 'transfer', $this->billing(), discountCodes: ['QUARTO']);

        $this->assertSame('75.00', $o->items->first()->pv_unit_frozen);
        $this->assertSame('150.00', $o->pv_total);
    }

    public function test_free_shipping_discount_is_recorded_on_the_order(): void
    {
        Notification::fake();
        $this->plan();
        $this->shipping('10.00');
        $p = $this->product('A', '100.00');
        $this->discount(['code' => 'GRATIS', 'type' => 'free_shipping', 'value' => 0]);
        $user = $this->buyer($this->activeSponsor());

        $o = $this->svc()->place($user, [$p->id => 1], 'IT', 'transfer', $this->billing(), discountCodes: ['GRATIS']);

        $this->assertSame('0.00', $o->shipping_net);
        $this->assertSame('10.00', $o->shipping_discount_net);
        $this->assertSame('122.00', $o->total);
    }

    public function test_credit_note_refunds_what_was_actually_paid(): void
    {
        Notification::fake();
        $this->plan();
        $p = $this->product('A', '100.00');
        $this->discount(['code' => 'DIECI']);
        $user = $this->buyer($this->activeSponsor());
        $o = $this->svc()->place($user, [$p->id => 2], 'IT', 'transfer', $this->billing(), discountCodes: ['DIECI']);
        $this->svc()->markPaid($o);

        $note = app(ReturnService::class)->refund($o->fresh(), [$o->items->first()->id => 1], 'prova');

        $this->assertSame('109.80', (string) $note->total); // 90 + 22%
    }

    public function test_stock_is_checked_decremented_and_restored_on_cancel(): void
    {
        Notification::fake();
        $this->plan();
        $p = $this->product('A', '100.00', ['stock_qty' => 3]);
        $free = $this->product('B', '100.00');
        $user = $this->buyer($this->activeSponsor());

        $o = $this->svc()->place($user, [$p->id => 2, $free->id => 1], 'IT', 'transfer', $this->billing());
        $this->assertSame(1, $p->fresh()->stock_qty);
        $this->assertNull($free->fresh()->stock_qty);

        try {
            $this->svc()->place($user, [$p->id => 2], 'IT', 'transfer', $this->billing());
            $this->fail('Ordine oltre la giacenza accettato');
        } catch (OrderException $e) {
            $this->assertStringContainsString('ne restano solo 1', $e->getMessage());
        }
        $this->assertSame(1, $p->fresh()->stock_qty);

        $this->svc()->cancel($o);
        $this->assertSame(3, $p->fresh()->stock_qty);
    }

    public function test_sold_out_blocks_but_backorder_allows(): void
    {
        Notification::fake();
        $this->plan();
        $out = $this->product('A', '100.00', ['stock_qty' => 0]);
        $pre = $this->product('B', '100.00', ['stock_qty' => 0, 'allow_backorder' => true]);
        $user = $this->buyer($this->activeSponsor());

        try {
            $this->svc()->place($user, [$out->id => 1], 'IT', 'transfer', $this->billing());
            $this->fail('Prodotto esaurito venduto');
        } catch (OrderException $e) {
            $this->assertStringContainsString('esaurito', $e->getMessage());
        }
        $order = $this->svc()->place($user, [$pre->id => 2], 'IT', 'transfer', $this->billing());
        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame(-2, $pre->fresh()->stock_qty);
    }
}
