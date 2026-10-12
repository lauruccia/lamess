<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Caso di conformità aperto a carico di un incaricato. */
class ComplianceCase extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'open'];

    /** @var array<string, string> */
    public const CATEGORIES = [
        'unapproved_material' => 'Materiale promozionale non approvato', 'misleading_claims' => 'Affermazioni ingannevoli o promesse di guadagno',
        'spam' => 'Comunicazioni indesiderate', 'forbidden_practice' => 'Pratica vietata dal regolamento', 'conflict' => 'Conflitto di interessi / concorrenza', 'other' => 'Altro',
    ];

    public const OPEN_STATUSES = ['open', 'investigating'];

    protected function casts(): array
    {
        return ['opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }
}
