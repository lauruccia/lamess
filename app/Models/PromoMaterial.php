<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Materiale promozionale: dell'azienda o proposto da un incaricato e approvato dal Consorzio. */
class PromoMaterial extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['shared' => 'boolean', 'decided_at' => 'immutable_datetime', 'valid_until' => 'immutable_date'];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'submitted_by_consultant_id');
    }

    /** Approvato e ancora valido. */
    public function scopeUsable(Builder $q): Builder
    {
        return $q->where('status', 'approved')->where(fn ($w) => $w->whereNull('valid_until')->orWhere('valid_until', '>=', now()->toDateString()));
    }
}
