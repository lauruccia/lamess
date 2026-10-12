<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'types' => 'array', 'due_at' => 'immutable_datetime', 'last_reminder_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime', 'canceled_at' => 'immutable_datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function isOpen(): bool
    {
        return $this->fulfilled_at === null && $this->canceled_at === null;
    }

    /** Nomi leggibili dei documenti richiesti. @return list<string> */
    public function labels(): array
    {
        $all = (array) config('lamess.kyc_document_types');

        return array_map(fn ($t) => $all[$t] ?? $t, (array) $this->types);
    }
}
