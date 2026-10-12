<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    protected $guarded = [];

    public const OPEN = 'open';

    public const CALCULATING = 'calculating';

    public const REVIEW = 'review';

    public const CLOSED = 'closed';

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(CompPlanVersion::class, 'comp_plan_version_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(PvMovement::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(CalculationRun::class);
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }
}
