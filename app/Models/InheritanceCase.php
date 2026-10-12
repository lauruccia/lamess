<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Decesso di un incaricato: documentazione, eredi, liquidazione dei compensi e destino dell'account (Reg. 9.2). */
class InheritanceCase extends Model
{
    protected $guarded = [];

    protected $attributes = ['status' => 'reported'];

    /** @var array<string, string> */
    public const DOCUMENT_TYPES = ['death_certificate' => 'Certificato di morte', 'will' => 'Disposizioni testamentarie (copia autentica)', 'court_order' => 'Provvedimento dell\'Autorità giudiziaria', 'other' => 'Altro'];

    protected function casts(): array
    {
        return [
            'death_date' => 'immutable_date', 'reported_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'liquidated_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime', 'estate_amount' => 'decimal:2',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(InheritanceDocument::class);
    }

    public function heirs(): HasMany
    {
        return $this->hasMany(InheritanceHeir::class);
    }
}
