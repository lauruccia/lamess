<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\BundleRule;
use App\Models\Order;
use App\Models\PvMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\OrderPaymentDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\ShopDb;
use Tests\TestCase;

final class ShopPagesTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function form(array $over = []): array
    {
        return $over + [
            'type' => 'private', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U',
            'address' => 'Via Roma 1', 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC',
            'payment_method' => 'card', 'privacy' => '1', 'subscribe' => 'none',
        ];
    }

    public function test_catalog_is_public_and_hides_inactive_products(): void
    {
        $this->product('A', '100.00', ['name' => 'Crema viso']);
        $this->product('B', '100.00', ['name' => 'Nascosto', 'active' => false]);
        $this->get('/negozio')->assertOk()->assertSee('Crema viso')->assertDontSee('Nascosto');
    }

    public function test_cart_shows_bundle_gift_preview_and_checkout_requires_login(): void
    {
        $box = $this->product('BOX', '100.00', ['name' => 'Box']);
        $gift = $this->product('G', '10.00', ['name' => 'Omaggio speciale']);
        BundleRule::create(['trigger_product_id' => $box->id, 'trigger_qty' => 5, 'gift_product_id' => $gift->id, 'gift_qty' => 1]);

        $this->post('/carrello/aggiungi', ['product_id' => $box->id, 'qty' => 5])->assertRedirect('/carrello');
        $this->get('/carrello')->assertOk()->assertSee('Omaggio speciale');
        $this->get('/area/checkout')->assertRedirect();
    }

    public function test_guest_with_cart_registers_and_lands_on_checkout(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $this->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        $this->get('/carrello')->assertOk()->assertSee('/registrazione');

        $this->post('/registrazione', [
            'type' => 'private', 'first_name' => 'Anna', 'last_name' => 'Bianchi', 'email' => 'anna@example.test',
            'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga', 'privacy' => '1',
        ])->assertRedirect('/area/checkout');
        $this->get('/area/checkout')->assertOk()->assertSee('Fatturazione');
    }

    public function test_full_purchase_flow_with_card_and_invoice_download(): void
    {
        Notification::fake();
        $this->plan();
        $this->shipping('5.00');
        $sponsor = $this->activeSponsor();
        $p = $this->product('A', '123.00', ['name' => 'Crema viso']);
        $user = $this->buyer($sponsor);

        $this->actingAs($user)->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 2]);
        $this->get('/area/checkout')->assertOk()->assertSee('Fatturazione');
        $this->post('/area/checkout', $this->form())->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame('paid', $order->status);
        $this->assertSame('200.00', number_format((float) PvMovement::sum('pv'), 2, '.', ''));
        $this->get("/area/ordini/{$order->id}")->assertOk()->assertSee($order->number)->assertSee('Fattura');
        $pdf = $this->get("/area/ordini/{$order->id}/fattura");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get('/area/ordini')->assertOk()->assertSee($order->number);
        $this->assertSame('Via Roma 1', $user->profile->fresh()->address, 'i dati di fatturazione si salvano nel profilo');
    }

    public function test_bank_transfer_flow_shows_instructions_and_stays_pending(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $user = $this->buyer($this->activeSponsor());
        $this->actingAs($user)->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        $this->post('/area/checkout', $this->form(['payment_method' => 'transfer']))->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame('pending_payment', $order->status);
        $this->get("/area/ordini/{$order->id}")->assertSee('Bonifico')->assertSee($order->number);
    }

    public function test_bank_transfer_order_emails_the_payment_details_to_the_customer(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => true, 'lamess.bank_transfer_instructions' => 'IBAN IT60X0542811101000000123456']);
        $this->plan();
        $p = $this->product('A', '100.00');
        $user = $this->buyer($this->activeSponsor());
        $this->actingAs($user)->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        $this->post('/area/checkout', $this->form(['payment_method' => 'transfer']))->assertRedirect();
        $order = Order::firstOrFail();

        Notification::assertSentTo($user, OrderPaymentDetails::class, fn ($n) => $n->order->is($order) && ! $n->reminder);
        $mail = (new OrderPaymentDetails($order))->toMail($user);
        $text = implode(' ', array_map('strval', $mail->introLines));
        $this->assertStringContainsString('IT60X0542811101000000123456', $text);
        $this->assertStringContainsString($order->number, $text);
    }

    public function test_card_order_does_not_send_bank_details(): void
    {
        Notification::fake();
        config(['lamess.mail_enabled' => true]);
        $this->plan();
        $p = $this->product('A', '100.00');
        $user = $this->buyer($this->activeSponsor());
        $this->actingAs($user)->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        $this->post('/area/checkout', $this->form(['payment_method' => 'card']));

        Notification::assertNotSentTo($user, OrderPaymentDetails::class);
    }

    public function test_checkout_validation_errors_are_shown(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $this->actingAs($this->buyer())->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        $this->post('/area/checkout', $this->form(['address' => '', 'privacy' => '']))->assertSessionHasErrors(['address', 'privacy']);
        $this->post('/area/checkout', $this->form(['tax_code' => '']))->assertSessionHasErrors('tax_code');
        $this->post('/area/checkout', $this->form(['first_name' => '', 'last_name' => '']))->assertSessionHasErrors(['first_name', 'last_name']);
        $this->post('/area/checkout', $this->form(['type' => 'company', 'company_name' => '', 'vat_number' => '']))->assertSessionHasErrors(['company_name', 'vat_number']);
        $this->assertSame(0, Order::count());
    }

    public function test_subscription_without_tax_code_creates_neither_subscription_nor_order(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $this->actingAs($this->buyer())->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        $this->post('/area/checkout', $this->form(['tax_code' => '', 'payment_method' => 'card', 'subscribe' => 'monthly']))->assertSessionHasErrors('tax_code');
        $this->assertSame(0, Order::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_orders_and_invoices_are_private_to_the_buyer(): void
    {
        $this->plan();
        $p = $this->product('A', '100.00');
        $a = $this->buyer($this->activeSponsor());
        $this->actingAs($a)->post('/carrello/aggiungi', ['product_id' => $p->id, 'qty' => 1]);
        $this->post('/area/checkout', $this->form());
        $order = Order::firstOrFail();

        $other = User::factory()->create();
        $this->actingAs($other)->get("/area/ordini/{$order->id}")->assertNotFound();
        $this->get("/area/ordini/{$order->id}/fattura")->assertNotFound();
    }
}
