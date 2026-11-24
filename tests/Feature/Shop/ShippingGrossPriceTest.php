<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\CartPricer;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ReturnService;
use App\Filament\Resources\ShippingRates\Pages\CreateShippingRate;
use App\Models\ShippingRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** La spedizione decisa IVA inclusa (es. 13,00 €) resta esatta in tariffa, carrello, ordine, fattura e reso. */
final class ShippingGrossPriceTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    public function test_form_saves_the_exact_net_of_a_gross_price(): void
    {
        $this->actingAs(User::factory()->role('super_admin')->create());

        Livewire::test(CreateShippingRate::class)->fillForm(['country_code' => 'NL', 'vat_rate' => '22'])
            ->set('data.amount_gross', '13')->call('create')->assertHasNoFormErrors();

        $rate = ShippingRate::where('country_code', 'NL')->firstOrFail();
        $this->assertSame('10.6557377', $rate->amount_net);
    }

    public function test_mixed_vat_rates_do_not_lose_a_cent(): void
    {
        // Prodotto 300,00 € IVA 10% + spedizione 13,00 € IVA 22%: 313,00, non 312,99.
        ShippingRate::create(['country_code' => 'IT', 'amount_net' => '10.65573770', 'vat_rate' => '22.00']);
        $p = $this->product('B', '272.72727273', ['vat_rate' => '10.00']);

        $priced = app(CartPricer::class)->price([['product' => $p, 'qty' => 1]], null);

        $this->assertSame('300.00', (string) $priced->itemsGross());
        $this->assertSame('13.00', (string) $priced->shippingGross());
        $this->assertSame('313.00', $priced->totals()['total']);
    }

    public function test_gross_13_stays_13_in_cart_order_invoice_and_refund(): void
    {
        Notification::fake();
        $this->plan();
        // 13,00 € IVA inclusa = 10,6557377 netto: con 2 decimali l'IVA darebbe 13,01 (10,65 → 12,99).
        ShippingRate::create(['country_code' => 'IT', 'amount_net' => '10.65573770', 'vat_rate' => '22.00']);
        $p = $this->product('A', '100.00');

        $priced = app(CartPricer::class)->price([['product' => $p, 'qty' => 1]], null);
        $this->assertSame('13.00', (string) $priced->shippingGross());
        $this->assertSame('135.00', $priced->totals()['total']);

        $orders = app(OrderService::class);
        $o = $orders->place($this->buyer($this->activeSponsor()), [$p->id => 1], 'IT', 'card', $this->billing());
        $this->assertSame('10.6557377', $o->shipping_net);
        $this->assertSame('135.00', $o->total);

        $orders->payByCard($o);
        $o = $o->fresh();
        $line = $o->invoice->lines()->where('description', 'Spese di spedizione')->firstOrFail();
        $this->assertSame('13.00', number_format((float) (string) $line->total, 2, '.', ''));
        $this->assertSame('135.00', (string) $o->invoice->total);

        $note = app(ReturnService::class)->refund($o, [$o->items->first()->id => 1], 'Reso con spedizione', false, true);
        $this->assertSame('135.00', (string) $note->total);
        $this->assertEqualsWithDelta(10.6557377, (float) (string) $o->fresh()->shipping_refunded_net, 0.0000001);
    }
}
