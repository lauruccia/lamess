<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimento del wallet provvigionale: il saldo è sempre la somma dei movimenti. */
class WalletTransaction extends Model
{
    protected $guarded = [];

    /** @var array<string, string> */
    public const TYPES = [
        'commission_credit' => 'Provvigioni accreditate', 'clawback' => 'Recupero (storni)', 'withdrawal' => 'Prelievo', 'transfer_fee' => 'Commissione bonifico',
        'withholding_tax' => 'Ritenuta d\'acconto', 'inps' => 'Contributo INPS (quota incaricato)', 'structure_bonus' => 'Bonus di Struttura', 'manual_adjustment' => 'Rettifica manuale', 'heir_liquidation' => 'Liquidazione agli eredi',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'balance_after' => 'decimal:2'];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
