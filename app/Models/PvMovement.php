<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Periods\PeriodStateException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvMovement extends Model
{
    protected $guarded = [];

    protected $table = 'pv_movements';

    /** Un movimento di un periodo chiuso è immutabile: le correzioni sono rettifiche nel periodo aperto. */
    protected static function booted(): void
    {
        $guard = static function (self $m): void {
            if ($m->period()->value('status') === Period::CLOSED) {
                throw new PeriodStateException('Movimento di un periodo chiuso: registra una rettifica nel periodo aperto.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['pv' => 'decimal:2', 'cv' => 'decimal:2', 'amount' => 'decimal:2', 'vap_amount' => 'decimal:2', 'event_at' => 'immutable_datetime', 'excluded_from_bonus' => 'boolean'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }
}
