<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['base_amount' => 'decimal:2', 'amount' => 'decimal:2', 'pct' => 'decimal:4', 'explanation' => 'array', 'decisions' => 'array'];
    }
}
