<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Richiesta di assistenza. I reclami su estratti conto e ricevute sono ticket con categoria dedicata. */
class Ticket extends Model
{
    protected $guarded = [];

    public const OPEN = 'open';

    public const ANSWERED = 'answered';

    public const CLOSED = 'closed';

    /** @var array<string, string> */
    public const CATEGORIES = [
        'general' => 'Domanda generale', 'technical' => 'Problema tecnico', 'statement_dispute' => 'Reclamo su estratto conto',
        'receipt_dispute' => 'Segnalazione su ricevuta', 'report' => 'Segnalazione di comportamento', 'other' => 'Altro',
    ];

    /** Categorie che l'utente può scegliere liberamente (i reclami nascono dalla pagina del documento). */
    public const USER_CATEGORIES = ['general', 'technical', 'report', 'other'];

    protected function casts(): array
    {
        return ['last_message_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo();
    }
}
