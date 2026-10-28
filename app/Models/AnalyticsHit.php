<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una pagina vista all'interno di una visita. */
class AnalyticsHit extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function visit(): BelongsTo
    {
        return $this->belongsTo(AnalyticsVisit::class, 'visit_id');
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
