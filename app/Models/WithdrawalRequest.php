<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Richiesta di prelievo dal wallet (solo con modalità di pagamento «su richiesta»). */
class WithdrawalRequest extends Model
{
    protected $guarded = [];

    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'gross' => 'decimal:2', 'withholding' => 'decimal:2', 'inps' => 'decimal:2', 'fee' => 'decimal:2', 'net' => 'decimal:2',
            'requested_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'due_by' => 'immutable_date:Y-m-d',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }

    /** Ancora impegna il saldo: richiesto o approvato ma non ancora pagato. */
    public function reservesBalance(): bool
    {
        return in_array($this->status, [self::REQUESTED, self::APPROVED], true);
    }
}
