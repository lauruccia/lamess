<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Domain\Customers\AddressCheck;
use App\Domain\Customers\ShippingAddressBook;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\ShippingLabelService;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use App\Models\ShippingAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ShopDb;
use Tests\TestCase;

/** Indirizzo di consegna: niente etichette con il solo «Piano 1»; l'admin può correggerlo prima della spedizione. */
final class DeliveryAddressTest extends TestCase
{
    use RefreshDatabase;
    use ShopDb;

    /** @return array<string,array{0:string,1:bool}> */
    public static function addresses(): array
    {
        return [
            'solo piano' => ['Piano 1', false],
            'solo scala e interno' => ['Scala B, interno 4', false],
            'vuoto' => ['   ', false],
            'via senza numero' => ['Via Roma', false],
            'via e numero' => ['Via Roma 5', true],
            'con piano dopo la via' => ['via Tauriana 5, Piano 1', true],
            'contrada senza numero' => ['Contrada Pezzo snc', true],
            'numero con barra' => ['Via dei Mille 12/A', true],
            'strada con chilometro' => ['SS 106 km 12', true],
        ];
    }

    #[DataProvider('addresses')]
    public function test_address_check(string $address, bool $complete): void
    {
        $this->assertSame($complete, AddressCheck::isComplete($address));
        $this->assertSame($complete, AddressCheck::problem($address) === null);
    }

    public function test_address_book_refuses_a_floor_only_address_and_saved_ones_are_caught_at_order_time(): void
    {
        $user = User::factory()->create();
        $book = app(ShippingAddressBook::class);
        $data = ['address' => 'Piano 1', 'city' => 'Reggio Calabria', 'zip' => '89128', 'province' => 'RC'];

        try {
            $book->add($user, $data);
            $this->fail('Un indirizzo con solo il piano non deve essere salvato.');
        } catch (OrderException $e) {
            $this->assertStringContainsString('Manca la via', $e->getMessage());
        }
        $this->assertSame(0, ShippingAddress::where('user_id', $user->id)->count());

        // Salvato prima che esistesse il controllo: lo si ferma quando lo si sceglie per un ordine.
        $old = ShippingAddress::create(['user_id' => $user->id, 'address' => 'Piano 1', 'city' => 'Reggio Calabria', 'zip' => '89128', 'country_code' => 'IT', 'is_default' => true]);
        $this->expectException(OrderException::class);
        $this->expectExceptionMessage('non è completo');
        $book->resolve($user, ['ship_to' => (string) $old->id], ['address' => 'Via Roma 1', 'city' => 'Reggio Calabria', 'zip' => '89128']);
    }

    public function test_billing_address_used_for_shipping_must_be_complete(): void
    {
        $this->expectException(OrderException::class);
        app(ShippingAddressBook::class)->resolve(User::factory()->create(), ['ship_to' => 'billing'], ['address' => 'Piano 1', 'city' => 'Reggio Calabria', 'zip' => '89128']);
    }

    private function orderWithBrokenAddress(string $status = 'paid'): Order
    {
        $buyer = User::factory()->create();
        $order = Order::create([
            'number' => 'ORD-ADDR-1', 'buyer_user_id' => $buyer->id, 'buyer_type' => 'customer', 'status' => $status, 'payment_method' => 'kmoney',
            'subtotal_net' => '10.00', 'vat_total' => '2.20', 'total' => '12.20', 'paid_at' => now(),
            'billing' => ['name' => 'Laura Gulli', 'address' => 'via Tauriana 5, Piano 1', 'city' => 'Reggio Calabria', 'zip' => '89128', 'province' => 'RC', 'country_code' => 'IT',
                'shipping' => ['recipient' => null, 'address' => 'Piano 1', 'city' => 'Reggio Calabria', 'zip' => '89128', 'province' => 'RC', 'phone' => '063721968']],
        ]);
        $order->items()->create(['product_id' => $this->product('XA', '5.00')->id, 'sku' => 'X', 'name' => 'Box prova', 'qty' => 1, 'unit_net' => '10.00', 'vat_rate' => '22.00']);

        return $order;
    }

    public function test_admin_corrects_the_delivery_address_and_the_label_follows(): void
    {
        $order = $this->orderWithBrokenAddress();
        $this->actingAs(User::factory()->role('super_admin')->create());

        Livewire::test(ListOrders::class)
            ->callTableAction('editShipping', $order, ['recipient' => null, 'address' => 'Via Tauriana 5, piano 1', 'zip' => '89128', 'city' => 'Reggio Calabria', 'province' => 'RC', 'phone' => '063721968'])
            ->assertNotified();

        $order->refresh();
        $this->assertSame('Via Tauriana 5, piano 1', $order->billing['shipping']['address']);
        $this->assertSame('via Tauriana 5, Piano 1', $order->billing['address'], 'la fatturazione non cambia');
        $this->assertContains('Via Tauriana 5, piano 1', app(ShippingLabelService::class)->label($order)['lines']);
        $event = $order->events()->where('type', 'shipping_address')->first();
        $this->assertNotNull($event);
        $this->assertTrue((bool) $event->internal);
        $this->assertStringContainsString('Piano 1', $event->message);
    }

    public function test_admin_cannot_save_another_incomplete_address(): void
    {
        $order = $this->orderWithBrokenAddress();
        $this->actingAs(User::factory()->role('super_admin')->create());

        Livewire::test(ListOrders::class)
            ->callTableAction('editShipping', $order, ['address' => 'Piano 2', 'zip' => '89128', 'city' => 'Reggio Calabria'])
            ->assertHasTableActionErrors(['address']);

        $this->assertSame('Piano 1', $order->refresh()->billing['shipping']['address']);
    }

    public function test_address_cannot_change_after_shipping(): void
    {
        $order = $this->orderWithBrokenAddress('shipped');

        $this->expectException(OrderException::class);
        app(OrderService::class)->updateShippingAddress($order, ['address' => 'Via Roma 5', 'zip' => '89128', 'city' => 'Reggio Calabria']);
    }
}
