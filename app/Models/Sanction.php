<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Sanzione: richiamo, blocco dei compensi, sospensione o cessazione. */
class Sanction extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'active'];

    /** @var array<string, string> */
    public const TYPES = ['warning' => 'Richiamo scritto', 'commission_hold' => 'Blocco dei compensi', 'suspension' => 'Sospensione', 'termination' => 'Cessazione'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'lifted_at' => 'immutable_datetime', 'had_hold_before' => 'boolean'];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }
}
