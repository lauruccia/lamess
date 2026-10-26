<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Firma di una versione della Lettera da parte di un incaricato già registrato (non tocca il suo contratto IVD originale). */
class IvdLetterSignature extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'evidence' => 'array', 'sent_at' => 'immutable_datetime', 'last_reminder_at' => 'immutable_datetime',
            'viewed_at' => 'immutable_datetime', 'signed_at' => 'immutable_datetime',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(IvdLetterVersion::class, 'ivd_letter_version_id');
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
