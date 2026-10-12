<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un utilizzo di uno sconto su un ordine. Si cancella se l'ordine viene annullato, così il limite d'uso si libera. */
class DiscountRedemption extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_net' => 'decimal:2'];
    }

    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
