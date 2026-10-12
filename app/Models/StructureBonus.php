<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bonus di Struttura (piano Incaricati): una riga per bonus, pagato una sola volta. */
class StructureBonus extends Model
{
    public const CALCULATED = 'calculated';

    public const CONFIRMED = 'confirmed';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'latest_amount' => 'decimal:2', 'explanation' => 'array',
            'calculated_at' => 'datetime', 'confirmed_at' => 'datetime', 'paid_at' => 'datetime',
        ];
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'beneficiary_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'source_consultant_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
