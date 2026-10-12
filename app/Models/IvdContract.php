<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IvdContract extends Model
{
    protected $guarded = [];

    protected $attributes = ['kind' => 'new'];

    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'signed_at' => 'immutable_datetime', 'starts_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime', 'rejected_at' => 'immutable_datetime',
        ];
    }

    public function events(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(IvdContractEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'sponsor_id');
    }
}
