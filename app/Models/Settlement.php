<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Liquidazione: quanto ha maturato un incaricato in un periodo chiuso. */
class Settlement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['accrued' => 'decimal:2', 'held' => 'decimal:2'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
