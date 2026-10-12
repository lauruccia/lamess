<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Orders\CartPricer;
use App\Models\ShippingRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Tariffe di spedizione per paese e fascia di peso. */
final class ShippingZonesTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    private function ship(array $entries, string $country = 'IT'): string
    {
        return (string) app(CartPricer::class)->price($entries, null, [], null, $country)->shippingNet;
    }

    public function test_weight_bands_pick_the_right_rate(): void
    {
        ShippingRate::create(['country_code' => 'IT', 'amount_net' => '5.00', 'min_weight_g' => 0, 'max_weight_g' => 1000]);
        ShippingRate::create(['country_code' => 'IT', 'amount_net' => '9.00', 'min_weight_g' => 1001, 'max_weight_g' => null]);
        $p = $this->product('A', '10.00', ['weight_g' => 600]);

        $this->assertSame('5.00', $this->ship([['product' => $p, 'qty' => 1]]));
        $this->assertSame('9.00', $this->ship([['product' => $p, 'qty' => 2]]));
    }

    public function test_rest_of_world_fallback_and_no_rate(): void
    {
        ShippingRate::create(['country_code' => 'IT', 'amount_net' => '5.00']);
        ShippingRate::create(['country_code' => '*', 'amount_net' => '20.00']);
        $p = $this->product('A', '10.00');

        $this->assertSame('20.00', $this->ship([['product' => $p, 'qty' => 1]], 'FR'));
        ShippingRate::where('country_code', '*')->update(['active' => false]);
        $this->assertSame('0', $this->ship([['product' => $p, 'qty' => 1]], 'FR'));
    }

    public function test_product_shipping_overrides_general_rate(): void
    {
        ShippingRate::create(['country_code' => 'IT', 'amount_net' => '7.38']);
        $plain = $this->product('A', '10.00');
        $free = $this->product('B', '10.00', ['shipping_net' => '0']);
        $bulky = $this->product('C', '10.00', ['shipping_net' => '16.39']);

        $this->assertSame('7.38', $this->ship([['product' => $plain, 'qty' => 1]]));
        $this->assertSame('0.00', $this->ship([['product' => $free, 'qty' => 3]]));
        $this->assertSame('7.38', $this->ship([['product' => $free, 'qty' => 1], ['product' => $plain, 'qty' => 1]]));
        $this->assertSame('16.39', $this->ship([['product' => $plain, 'qty' => 1], ['product' => $bulky, 'qty' => 2]]));
    }

    public function test_admin_sets_general_shipping_vat_included(): void
    {
        $this->actingAs(\App\Models\User::factory()->role('admin_finance')->create());
        \Livewire\Livewire::test(\App\Filament\Resources\ShippingRates\Pages\CreateShippingRate::class)
            ->fillForm(['country_code' => 'IT', 'amount_gross' => '9', 'vat_rate' => 22, 'min_weight_g' => 0, 'active' => true])
            ->call('create')->assertHasNoFormErrors();
        $this->assertEqualsWithDelta(7.38, (float) ShippingRate::where('country_code', 'IT')->firstOrFail()->amount_net, 0.001);
    }
}
