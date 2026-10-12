<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Models\BundleRule;
use App\Models\KitComponent;
use App\Models\Order;
use App\Models\PvMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class OrdersTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function svc(): OrderService
    {
        return app(OrderService::class);
    }

    public function test_pv_defaults_to_net_over_1_23_and_cv_to_pv_with_promo_doubling(): void
    {
        $a = $this->product('A', '123.00');
        $b = $this->product('B', '123.00', ['promo_rank_double_cv' => true]);
        $c = $this->product('C', '123.00', ['pv' => '80.00', 'cv' => '70.00']);
        $this->assertSame('100.00', $a->pvUnit());
        $this->assertSame('100.00', $a->cvUnit());
        $this->assertSame('100.00', $b->pvUnit());
        $this->assertSame('200.00', $b->cvUnit());
        $this->assertSame('80.00', $c->pvUnit());
        $this->assertSame('70.00', $c->cvUnit());
    }

    public function test_order_totals_freeze_values_and_exclude_shipping_from_pv(): void
    {
        $this->plan();
        $this->shipping('10.00');
        $p = $this->product('A', '123.00');
        $o = $this->svc()->place($this->buyer($this->activeSponsor()), [$p->id => 2], 'IT', 'card', $this->billing());

        $this->assertSame('246.00', $o->subtotal_net);
        $this->assertSame('10.00', $o->shipping_net);
        $this->assertSame('56.32', $o->vat_total);   // 22% su 256,00
        $this->assertSame('312.32', $o->total);
        $this->assertSame('200.00', $o->pv_total);   // la spedizione non genera PV (OPEN-18)

        $p->update(['net_price' => '200.00']);       // il freeze protegge l'ordine esistente
        $this->assertSame('100.00', $o->fresh()->items->first()->pv_unit_frozen);
    }

    public function test_bundle_gifts_are_added_with_zero_pv_and_repeat(): void
    {
        $this->plan();
        $box = $this->product('BOX', '123.00');
        $gift = $this->product('GIFT', '50.00');
        BundleRule::create(['trigger_product_id' => $box->id, 'trigger_qty' => 5, 'gift_product_id' => $gift->id, 'gift_qty' => 1]);
        $u = $this->buyer($this->activeSponsor());

        $four = $this->svc()->place($u, [$box->id => 4], 'IT', 'transfer', $this->billing());
        $this->assertSame(0, $four->items->where('is_gift', true)->count());

        $eleven = $this->svc()->place($u, [$box->id => 11], 'IT', 'transfer', $this->billing());
        $g = $eleven->items->firstWhere('is_gift', true);
        $this->assertSame(2, $g->qty);
        $this->assertSame('0.00000000', $g->unit_net);
        $this->assertSame('0.00', $g->pv_unit_frozen);
        $this->assertSame('1100.00', $eleven->pv_total);
    }

    public function test_kit_components_have_zero_price_and_pv_and_link_to_parent(): void
    {
        $this->plan();
        $kit = $this->product('KIT', '246.00', ['is_kit' => true]);
        $x = $this->product('X', '10.00');
        KitComponent::create(['kit_product_id' => $kit->id, 'component_product_id' => $x->id, 'qty' => 3]);
        $o = $this->svc()->place($this->buyer($this->activeSponsor()), [$kit->id => 2], 'IT', 'transfer', $this->billing());

        $comp = $o->items->firstWhere('sku', 'X');
        $this->assertSame(6, $comp->qty);
        $this->assertSame('0.00000000', $comp->unit_net);
        $this->assertSame($o->items->firstWhere('sku', 'KIT')->id, $comp->parent_item_id);
        $this->assertSame('492.00', $o->subtotal_net);
        $this->assertSame('400.00', $o->pv_total);
    }

    public function test_card_payment_confirms_pv_for_the_assigned_consultant_as_pvf_in_the_payment_month(): void
    {
        Notification::fake();
        $this->plan();
        $sponsor = $this->activeSponsor();
        $p = $this->product('A', '123.00');
        $o = $this->svc()->place($this->buyer($sponsor), [$p->id => 1], 'IT', 'card', $this->billing());
        $this->assertSame(0, PvMovement::count(), 'nessun PV prima del pagamento');

        $this->assertTrue($this->svc()->payByCard($o));

        $m = PvMovement::firstOrFail();
        $this->assertSame('paid', $o->fresh()->status);
        $this->assertSame('confirmed', $m->status);
        $this->assertSame('pvf', $m->kind);
        $this->assertSame($sponsor->id, $m->consultant_id);
        $this->assertSame('100.00', $m->pv);
        $this->assertSame('150.06', $m->amount, 'il movimento porta l’importo in euro IVA inclusa');
        $this->assertSame($o->id, $m->order_id);
        $this->assertSame(now('Europe/Rome')->format('Y-m'), $m->period->code);
        $this->assertNotNull($o->fresh()->invoice);
    }

    public function test_consultant_purchase_generates_pvc_for_himself(): void
    {
        $this->plan();
        $c = $this->activeSponsor();
        $c->update(['user_id' => ($u = $this->buyer())->id]);
        $p = $this->product('A', '123.00');
        $o = $this->svc()->place($u, [$p->id => 1], 'IT', 'card', $this->billing());
        $this->svc()->payByCard($o);

        $m = PvMovement::firstOrFail();
        $this->assertSame('consultant', $o->buyer_type);
        $this->assertSame('pvc', $m->kind);
        $this->assertSame($c->id, $m->consultant_id);
    }

    public function test_declined_card_leaves_order_pending_and_creates_no_points(): void
    {
        $this->plan();
        $p = $this->product('A', '123.00');
        $o = $this->svc()->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing());
        $this->assertFalse($this->svc()->payByCard($o, ['simulate' => 'decline']));
        $this->assertSame('pending_payment', $o->fresh()->status);
        $this->assertSame(0, PvMovement::count());
        $this->assertTrue($this->svc()->payByCard($o->fresh()), 'si può riprovare');
    }

    public function test_g22_bank_transfer_creates_pending_movement_that_blocks_close_until_confirmed(): void
    {
        Notification::fake();
        $this->plan();
        $period = $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        $p = $this->product('A', '123.00');
        $o = $this->svc()->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'transfer', $this->billing());

        $m = PvMovement::firstOrFail();
        $this->assertSame('pending_payment', $m->status);
        $this->assertSame($period->id, $m->period_id);

        $this->svc()->markPaid($o);
        $this->assertSame('confirmed', $m->fresh()->status);
        $this->assertSame(1, PvMovement::count(), 'il movimento in attesa viene confermato, non duplicato');
    }

    public function test_cancel_pending_order_cancels_its_movements(): void
    {
        $this->plan();
        $this->openPeriod(now('Europe/Rome')->format('Y-m'));
        $p = $this->product('A', '123.00');
        $o = $this->svc()->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'transfer', $this->billing());
        $this->svc()->cancel($o);
        $this->assertSame('cancelled', $o->fresh()->status);
        $this->assertSame('cancelled', PvMovement::firstOrFail()->status);
        $this->expectException(OrderException::class);
        $this->svc()->markPaid($o->fresh());
    }

    public function test_order_without_assigned_consultant_is_a_house_sale_without_points(): void
    {
        $this->plan();
        $p = $this->product('A', '123.00');
        $o = $this->svc()->place($this->buyer(), [$p->id => 1], 'IT', 'card', $this->billing());
        $this->svc()->payByCard($o);
        $this->assertSame(0, PvMovement::count());
        $this->assertSame('paid', $o->fresh()->status);
    }

    public function test_validations(): void
    {
        $this->plan();
        $p = $this->product('A', '123.00');
        $off = $this->product('OFF', '10.00', ['active' => false]);
        $it = $this->product('ONLYFR', '10.00', ['countries' => ['FR']]);
        $u = $this->buyer();
        $check = function (array $lines, array $billing, string $country = 'IT', string $msg = '') use ($u) {
            try {
                $this->svc()->place($u, $lines, $country, 'card', $billing);
                $this->fail("atteso errore: {$msg}");
            } catch (OrderException $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        };
        $check([], $this->billing(), 'IT', 'vuoto');
        $check([$p->id => 1], $this->billing(), 'FR', 'paese');
        $check([$off->id => 1], $this->billing(), 'IT', 'non è disponibile');
        $check([$it->id => 1], $this->billing(), 'IT', 'non è disponibile');
        $check([$p->id => 1], $this->billing(['address' => '']), 'IT', 'indirizzo');
        $check([$p->id => 1], $this->billing(['tax_code' => '']), 'IT', 'codice fiscale');
        $check([$p->id => 1], $this->billing(['type' => 'company', 'company_name' => 'ACME', 'vat_number' => '01234567890']), 'IT', 'SDI');
    }

    public function test_order_numbers_are_unique_and_prefixed(): void
    {
        $this->plan();
        $p = $this->product('A', '10.00');
        $u = $this->buyer();
        $a = $this->svc()->place($u, [$p->id => 1], 'IT', 'transfer', $this->billing());
        $b = $this->svc()->place($u, [$p->id => 1], 'IT', 'transfer', $this->billing());
        $this->assertNotSame($a->number, $b->number);
        $this->assertMatchesRegularExpression('/^ORD-\d{4}-\d{5}$/', $a->number);
        $this->assertSame(2, Order::count());
    }
}
