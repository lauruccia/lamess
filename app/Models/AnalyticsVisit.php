<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una visita al sito (sessione con pause inferiori a 30 minuti). Vedi App\Domain\Analytics. */
class AnalyticsVisit extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hits(): HasMany
    {
        return $this->hasMany(AnalyticsHit::class, 'visit_id');
    }

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'last_seen_at' => 'datetime', 'is_staff' => 'boolean'];
    }
}
