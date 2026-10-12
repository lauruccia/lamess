<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Richiesta di cessione del codice attività e dell'account a un altro utente (Reg. 9.1). */
class AccountTransfer extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'awaiting_payment'];

    public const OPEN = ['awaiting_payment', 'paid'];

    protected function casts(): array
    {
        return [
            'fee_amount' => 'decimal:2', 'balance_at_decision' => 'decimal:2', 'balance_follows_account' => 'boolean',
            'fee_paid_at' => 'immutable_datetime', 'requested_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function assignor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignor_user_id')->withoutGlobalScopes();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id')->withoutGlobalScopes();
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }
}
