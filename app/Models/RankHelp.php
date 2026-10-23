<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Agenti regalati a un incaricato per aiutarlo a qualificarsi. Vedi {@see \App\Domain\Ledger\RankHelpGrant}. */
class RankHelp extends Model
{
    protected $guarded = [];

    protected $table = 'rank_helps';

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'valid_until' => 'immutable_date', 'quantity' => 'integer'];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
