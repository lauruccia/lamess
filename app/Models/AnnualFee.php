<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnnualFee extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'reminders_sent' => 'array', 'amount' => 'decimal:2'];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }
}
