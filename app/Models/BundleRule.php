<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BundleRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['repeatable' => 'boolean', 'active' => 'boolean', 'active_from' => 'date', 'active_to' => 'date'];
    }

    public function trigger(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'trigger_product_id');
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'gift_product_id');
    }

    /** Quante unità omaggio spettano per $qty unità del prodotto trigger alla data indicata. */
    public function giftsFor(int $qty, \DateTimeInterface $on): int
    {
        if (! $this->active || $qty < $this->trigger_qty) {
            return 0;
        }
        $day = CarbonImmutable::instance(Carbon::parse($on))->startOfDay();
        if (($this->active_from && $day->lt($this->active_from)) || ($this->active_to && $day->gt($this->active_to))) {
            return 0;
        }

        return $this->repeatable ? intdiv($qty, $this->trigger_qty) * $this->gift_qty : $this->gift_qty;
    }
}
