<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Consultant;
use App\Models\Product;
use App\Models\Profile;
use App\Models\ShippingRate;
use App\Models\User;

trait ShopDb
{
    use OnboardingDb;

    protected function product(string $sku, string $net, array $over = []): Product
    {
        return Product::create($over + ['sku' => $sku, 'name' => "Prodotto {$sku}", 'net_price' => $net, 'vat_rate' => '22.00']);
    }

    /** @return array<string,mixed> */
    protected function billing(array $over = []): array
    {
        return $over + [
            'type' => 'private', 'first_name' => 'Mario', 'last_name' => 'Rossi', 'tax_code' => 'RSSMRA80A01H501U', 'phone' => '333 1234567',
            'address' => 'Via Roma 1', 'city' => 'Reggio Calabria', 'zip' => '89100', 'province' => 'RC',
        ];
    }

    protected function shipping(string $net = '5.00'): void
    {
        ShippingRate::create(['country_code' => 'IT', 'amount_net' => $net, 'vat_rate' => '22.00']);
    }

    protected function buyer(?Consultant $sponsor = null): User
    {
        $user = $this->fan($sponsor)->user;
        // Il checkout usa i dati di fatturazione già salvati nel profilo (e blocca l'indirizzo): il cliente di prova li ha già inseriti.
        Profile::updateOrCreate(['user_id' => $user->id], $this->billing() + ['country_code' => 'IT']);

        return $user;
    }
}
