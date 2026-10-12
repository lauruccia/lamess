<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingAddress extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Come si stampa nei menu e negli ordini: «Casa — Via Roma 1, 89100 Reggio Calabria (RC)». */
    public function oneLine(): string
    {
        $place = trim("{$this->zip} {$this->city}".($this->province ? " ({$this->province})" : ''));

        return trim(($this->label ? "{$this->label} — " : '')."{$this->address}, {$place}");
    }
}
