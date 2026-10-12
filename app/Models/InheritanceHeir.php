<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InheritanceHeir extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'defined'];

    protected function casts(): array
    {
        return ['share_percent' => 'decimal:3', 'amount' => 'decimal:2', 'paid_at' => 'immutable_datetime'];
    }

    public function inheritanceCase(): BelongsTo
    {
        return $this->belongsTo(InheritanceCase::class);
    }
}
