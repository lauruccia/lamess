<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\ShippingLabelService;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class ShippingLabelsTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function order(string $number, string $status = 'paid'): Order
    {
        $buyer = User::factory()->create();
        $o = Order::create([
            'number' => $number, 'buyer_user_id' => $buyer->id, 'buyer_type' => 'customer', 'status' => $status, 'payment_method' => 'card',
            'subtotal_net' => '10.00', 'vat_total' => '2.20', 'total' => '12.20', 'paid_at' => now(),
            'billing' => ['name' => 'Mario Rossi', 'address' => 'Via Roma 1', 'city' => 'Arezzo', 'zip' => '52100', 'province' => 'AR', 'country_code' => 'IT',
                'shipping' => ['recipient' => 'Anna Verdi', 'address' => 'Via Dante 5', 'city' => 'Siena', 'zip' => '53100', 'province' => 'SI', 'phone' => '333 1234567']],
        ]);
        $o->items()->create(['product_id' => $this->product('X'.$number, '5.00')->id, 'sku' => 'X', 'name' => 'Box prova', 'qty' => 2, 'unit_net' => '5.00', 'vat_rate' => '22.00']);

        return $o;
    }

    public function test_label_uses_delivery_address_and_falls_back_to_billing(): void
    {
        $o = $this->order('ORD-L1');
        $label = app(ShippingLabelService::class)->label($o);
        $this->assertSame('Anna Verdi', $label['name']);
        $this->assertContains('53100 Siena (SI)', $label['lines']);
        $this->assertSame(2, $label['pieces']);

        $o->update(['billing' => ['name' => 'Mario Rossi', 'address' => 'Via Roma 1', 'city' => 'Arezzo', 'zip' => '52100']]);
        $label = app(ShippingLabelService::class)->label($o->refresh());
        $this->assertSame('Mario Rossi', $label['name']);
        $this->assertContains('Via Roma 1', $label['lines']);
    }

    public function test_admin_prints_bulk_sheet_and_unpaid_orders_are_skipped(): void
    {
        $a = $this->order('ORD-L2');
        $b = $this->order('ORD-L3');
        $c = $this->order('ORD-L4', 'pending_payment');
        $admin = User::factory()->role('super_admin')->create();

        $r = $this->actingAs($admin)->get("/admin-files/orders/etichette?ids={$a->id},{$b->id},{$c->id}")->assertOk();
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->actingAs($admin)->get("/admin-files/orders/etichette?ids={$c->id}")->assertStatus(422);
    }

    public function test_a_single_label_has_the_same_a4_format_as_the_sheet_of_eight(): void
    {
        $a = $this->order('ORD-L6');
        $b = $this->order('ORD-L7');
        $admin = User::factory()->role('super_admin')->create();

        $single = $this->actingAs($admin)->get("/admin-files/orders/etichette?ids={$a->id}")->assertOk()->getContent();
        $sheet = $this->actingAs($admin)->get("/admin-files/orders/etichette?ids={$a->id},{$b->id}")->assertOk()->getContent();

        foreach ([$single, $sheet] as $pdf) {
            $this->assertSame(1, preg_match('/MediaBox\s*\[\s*[\d.]+\s+[\d.]+\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m));
            $this->assertEqualsWithDelta(595.28, (float) $m[1], 0.5);
            $this->assertEqualsWithDelta(841.89, (float) $m[2], 0.5);
        }
        $this->assertSame(8, ShippingLabelService::PER_SHEET);
    }

    public function test_customers_cannot_print_labels(): void
    {
        $a = $this->order('ORD-L5');
        $this->actingAs(User::factory()->create())->get("/admin-files/orders/etichette?ids={$a->id}")->assertForbidden();
    }
}
