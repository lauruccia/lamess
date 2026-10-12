<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pagamento (bonifico) a un incaricato. */
class Payout extends Model
{
    protected $guarded = [];

    public const TO_PAY = 'to_pay';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'withholding_amount' => 'decimal:2', 'inps_amount' => 'decimal:2', 'fee_amount' => 'decimal:2', 'paid_at' => 'immutable_datetime', 'exported_at' => 'immutable_datetime'];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Importo lordo del pagamento (i pagamenti storici senza trattenute hanno solo `amount`). */
    public function gross(): string
    {
        return (string) ($this->gross_amount ?? $this->amount);
    }

    public function structureBonuses(): HasMany
    {
        return $this->hasMany(StructureBonus::class);
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(Settlement::class);
    }
}
