<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNoteLine extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['unit_net' => 'decimal:8', 'vat_rate' => 'decimal:2', 'net' => 'decimal:2', 'vat' => 'decimal:2', 'total' => 'decimal:2'];
    }
}
