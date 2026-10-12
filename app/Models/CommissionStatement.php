<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estratto conto provvigionale pubblicato: dalla pubblicazione l'incaricato ha 10 giorni per un reclamo circostanziato, poi si considera accettato. */
class CommissionStatement extends Model
{
    protected $guarded = [];

    public const PUBLISHED = 'published';

    public const DISPUTED = 'disputed';

    public const ACCEPTED = 'accepted';

    public const RESOLVED = 'resolved';

    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'dispute_deadline_at' => 'immutable_datetime', 'accepted_at' => 'immutable_datetime',
            'disputed_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function canBeDisputed(?\DateTimeInterface $now = null): bool
    {
        return $this->status === self::PUBLISHED && ($now ?? now())->getTimestamp() <= $this->dispute_deadline_at->getTimestamp();
    }
}
