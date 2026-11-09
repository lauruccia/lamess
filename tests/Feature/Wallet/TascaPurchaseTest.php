<?php

declare(strict_types=1);

namespace Tests\Feature\Wallet;

use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Wallet\TascaPurchase;
use App\Domain\Wallet\WalletService;
use App\Models\Consultant;
use App\Models\Order;
use App\Models\Profile;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Acquisti pagati con i compensi della Tasca: +25% di valore, solo compensi liberi, annullo e reso. */
final class TascaPurchaseTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;
    use ShopDb;

    /** @return array{0: User, 1: Consultant} incaricato attivo con $credit € di compensi liberi nella Tasca */
    private function incaricato(string $credit = '120.00'): array
    {
        $this->plan(['payout_mode' => 'on_request']);
        $this->shipping('9.00');
        $user = User::factory()->create();
        Profile::updateOrCreate(['user_id' => $user->id], $this->billing() + ['country_code' => 'IT']);
        $c = $this->consultant('T1', null, 'DIAMOND');
        $c->update(['user_id' => $user->id]);
        app(WalletService::class)->post($c, 'commission_credit', $credit, 'Compensi di prova');

        return [$user, $c->refresh()];
    }

    private function box(): \App\Models\Product
    {
        // 150 € IVA inclusa
        return $this->product('BOX', '122.95');
    }

    public function test_usable_balance_and_cover_apply_the_bonus(): void
    {
        [, $c] = $this->incaricato();
        $t = app(TascaPurchase::class);

        $this->assertSame('120.00', (string) $t->usable($c));
        $cover = $t->cover($t->usable($c), \Brick\Math\BigDecimal::of('150.00'), \Brick\Math\BigDecimal::of('159.00'));
        $this->assertSame('150.00', (string) $cover['amount']);
        $this->assertSame('120.00', (string) $cover['used']);
        $this->assertSame('30.00', (string) $cover['bonus']);
    }

    public function test_box_is_fully_paid_with_120_and_shipping_is_free(): void
    {
        [$user, $c] = $this->incaricato();
        $box = $this->box();

        $o = app(OrderService::class)->place($user, [$box->id => 1], 'IT', 'card', $this->billing(), useTasca: true);

        $this->assertSame('tasca', $o->payment_method);
        $this->assertSame('paid', $o->status);
        $this->assertSame('150.00', $o->total);
        $this->assertSame('120.00', $o->tasca_used);
        $this->assertSame('30.00', $o->tasca_bonus);
        $this->assertSame('0.00', (string) app(WalletService::class)->balance($c->id));
    }

    public function test_partial_use_leaves_the_rest_to_the_chosen_method(): void
    {
        [$user, $c] = $this->incaricato('40.00');
        $box = $this->box();

        $o = app(OrderService::class)->place($user, [$box->id => 1], 'IT', 'transfer', $this->billing(), useTasca: true);

        $this->assertSame('50.00', $o->tasca_amount);
        $this->assertSame('40.00', $o->tasca_used);
        $this->assertSame('transfer', $o->payment_method);
        $this->assertSame('pending_payment', $o->status);
        $this->assertSame('100.00', (string) $o->restDue());   // spedizione gratuita con la Tasca: 150 − 50
        $this->assertSame('0.00', (string) $o->shipping_net);
        $this->assertSame('150.00', $o->total);
    }

    public function test_cancelling_gives_the_compensi_back(): void
    {
        [$user, $c] = $this->incaricato('40.00');
        $o = app(OrderService::class)->place($user, [$this->box()->id => 1], 'IT', 'transfer', $this->billing(), useTasca: true);
        $this->assertSame('0.00', (string) app(WalletService::class)->balance($c->id));

        app(OrderService::class)->cancel($o);

        $this->assertSame('40.00', (string) app(WalletService::class)->balance($c->id));
        $this->assertSame(1, WalletTransaction::where('consultant_id', $c->id)->where('type', 'purchase_release')->count());
    }

    public function test_not_available_for_customers_and_with_no_balance(): void
    {
        $this->plan(['payout_mode' => 'on_request']);
        $this->shipping('9.00');
        $buyer = $this->buyer($this->activeSponsor());

        $this->expectException(OrderException::class);
        app(OrderService::class)->place($buyer, [$this->box()->id => 1], 'IT', 'card', $this->billing(), useTasca: true);
    }

    public function test_tasca_cannot_be_combined_with_kmoney(): void
    {
        [$user] = $this->incaricato();

        $this->expectException(OrderException::class);
        app(OrderService::class)->place($user, [$this->box()->id => 1], 'IT', 'kmoney', $this->billing(), useTasca: true);
    }

    public function test_refunded_purchase_credit_is_not_withdrawable(): void
    {
        [$user, $c] = $this->incaricato('120.00');
        $o = app(OrderService::class)->place($user, [$this->box()->id => 1], 'IT', 'card', $this->billing(), useTasca: true);

        app(TascaPurchase::class)->refund($o, \Brick\Math\BigDecimal::of('150.00'));

        $wallet = app(WalletService::class);
        $this->assertSame('120.00', (string) $wallet->balance($c->id));
        $c->update(['kyc_status' => 'verified', 'iban' => 'IT60X0542811101000000123456', 'iban_holder' => 'T1']);
        $this->assertSame('0.00', $wallet->situation($c->refresh())['available']);
        $this->assertSame('120.00', (string) app(TascaPurchase::class)->usable($c));
    }

    public function test_checkout_shows_the_toggle_and_requires_the_consent(): void
    {
        [$user] = $this->incaricato();
        $box = $this->box();
        $form = $this->billing() + ['payment_method' => 'card', 'privacy' => '1', 'subscribe' => 'none', 'use_tasca' => '1'];

        $this->actingAs($user)->withSession(['cart' => [$box->id => 1], 'checkout_draft' => ['payment_method' => 'card', 'subscribe' => 'none']])
            ->get('/area/checkout/pagamento')->assertOk()->assertSee('Usa i compensi della Tasca')->assertSee('+25%')->assertSee('Spedizione gratuita')->assertSee('diventa gratuita')->assertSee('data-tasca-on', false);

        $this->actingAs($user)->withSession(['cart' => [$box->id => 1]])->post('/area/checkout', $form)->assertSessionHasErrors('tasca_consent');
        $this->assertSame(0, Order::count());

        $this->actingAs($user)->withSession(['cart' => [$box->id => 1]])->post('/area/checkout', $form + ['tasca_consent' => '1'])->assertRedirect();
        $o = Order::firstOrFail();
        $this->assertSame('tasca', $o->payment_method);
        $this->assertSame('paid', $o->status);
        $this->assertTrue($o->events()->where('type', 'tasca_consent')->exists());
    }

    public function test_customers_do_not_see_the_toggle(): void
    {
        $this->plan(['payout_mode' => 'on_request']);
        $this->shipping('9.00');
        $buyer = $this->buyer($this->activeSponsor());

        $this->actingAs($buyer)->withSession(['cart' => [$this->box()->id => 1]])->get('/area/checkout/pagamento')->assertOk()->assertDontSee('Usa i compensi della Tasca');
    }

    public function test_partial_order_asks_only_for_the_rest_in_mail_and_page(): void
    {
        [$user] = $this->incaricato('40.00');
        $o = app(OrderService::class)->place($user, [$this->box()->id => 1], 'IT', 'transfer', $this->billing(), useTasca: true);

        $mail = (new \App\Notifications\OrderPaymentDetails($o))->toMail($user);
        $this->assertStringContainsString('100,00', implode(' ', array_map('strval', $mail->introLines)));
        $this->actingAs($user)->get("/area/ordini/{$o->id}")->assertOk()->assertSee('100,00');
    }

    public function test_customer_can_cancel_a_pending_card_order_that_holds_compensi(): void
    {
        [$user, $c] = $this->incaricato('40.00');
        $o = app(OrderService::class)->place($user, [$this->box()->id => 1], 'IT', 'card', $this->billing(), useTasca: true);

        $this->actingAs($user)->post("/area/ordini/{$o->id}/annulla")->assertRedirect();

        $this->assertSame('cancelled', $o->refresh()->status);
        $this->assertSame('40.00', (string) app(WalletService::class)->balance($c->id));
    }

    public function test_a_period_cannot_be_reopened_after_compensi_were_spent(): void
    {
        [$user, $c] = $this->incaricato();
        app(OrderService::class)->place($user, [$this->box()->id => 1], 'IT', 'card', $this->billing(), useTasca: true);
        $period = (new \App\Models\Period)->forceFill(['closed_at' => now()->subDay()]);

        $this->expectException(\App\Domain\Wallet\WalletException::class);
        app(WalletService::class)->assertReversible($period);
    }
}
