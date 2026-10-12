<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Engine\Support\Num;
use App\Domain\Orders\OrderService;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['unit_net' => 'decimal:8', 'list_unit_net' => 'decimal:8', 'vat_rate' => 'decimal:2', 'pv_unit_frozen' => 'decimal:2', 'cv_unit_frozen' => 'decimal:2', 'is_gift' => 'boolean', 'vap_value' => 'decimal:4'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** V.A.P. in euro di $qty pezzi di questa riga (percentuale del prezzo IVA incl. già scontato, oppure importo fisso per pezzo); null = vale il piano. */
    public function vapAmount(int $qty): ?BigDecimal
    {
        if ($this->vap_value === null || $this->is_gift) {
            return null;
        }
        $value = Num::of((string) $this->vap_value);
        if ($this->vap_mode === Product::VAP_PERCENT) {
            return Num::money(OrderService::grossUnit($this)->multipliedBy($qty)->multipliedBy($value)->dividedBy(100, 10, \Brick\Math\RoundingMode::HalfUp));
        }
        if ($this->vap_mode === Product::VAP_FIXED) {
            return Num::money($value->multipliedBy($qty));
        }

        return null;
    }
}
