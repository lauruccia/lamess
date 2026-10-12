<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ricevuta provvigionale emessa dal Consorzio per conto dell'incaricato a fronte di un pagamento (Reg. 5.1). */
class CommissionReceipt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'gross' => 'decimal:2', 'taxable_base' => 'decimal:2', 'withholding' => 'decimal:2', 'inps_base' => 'decimal:2', 'inps_total' => 'decimal:2',
            'inps_worker' => 'decimal:2', 'fee' => 'decimal:2', 'net' => 'decimal:2', 'params' => 'array', 'issued_at' => 'immutable_datetime',
            'dispute_deadline_at' => 'immutable_datetime', 'disputed_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
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

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function canBeDisputed(?\DateTimeInterface $now = null): bool
    {
        return $this->status === 'published' && ($now ?? now())->getTimestamp() <= $this->dispute_deadline_at->getTimestamp();
    }
}
