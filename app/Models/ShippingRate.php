<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_net' => 'decimal:2', 'vat_rate' => 'decimal:2', 'active' => 'boolean'];
    }

    /** La tariffa che vale per paese e peso (g): prima il paese, altrimenti «*» (resto del mondo); la fascia più stretta vince. */
    public static function forCountryAndWeight(string $country, int $weightG = 0): ?self
    {
        foreach ([strtoupper($country), '*'] as $code) {
            $rates = static::where('country_code', $code)->where('active', true)->get()
                ->filter(fn (self $r) => $weightG >= (int) $r->min_weight_g && ($r->max_weight_g === null || $weightG <= (int) $r->max_weight_g));
            if ($rates->isNotEmpty()) {
                return $rates->sortByDesc('min_weight_g')->first();
            }
        }

        return null;
    }
}
