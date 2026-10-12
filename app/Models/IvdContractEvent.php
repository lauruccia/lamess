<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IvdContractEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'after_signing' => 'boolean'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(IvdContract::class, 'ivd_contract_id');
    }
}
