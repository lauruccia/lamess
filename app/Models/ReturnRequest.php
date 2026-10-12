<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Richiesta di reso fatta dal cliente dalla pagina dell'ordine; la decide l'admin. */
class ReturnRequest extends Model
{
    public const REQUESTED = 'requested';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'decided_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Approvata', self::REJECTED => 'Rifiutata', default => 'In attesa di risposta',
        };
    }
}
