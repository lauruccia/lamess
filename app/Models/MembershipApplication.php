<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Candidatura inviata dalla pagina pubblica «Candidati», in attesa (o già decisa) dal Consorzio. */
class MembershipApplication extends Model
{
    public const INTERESTS = ['customer' => 'Cliente', 'consultant' => 'Incaricato alla vendita'];

    public const STATUS_LABELS = ['pending' => 'Da valutare', 'approved' => 'Approvata', 'rejected' => 'Rifiutata', 'registered' => 'Registrato'];

    protected $guarded = [];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'privacy_at' => 'immutable_datetime', 'token_expires_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime',
            'email_sent_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime',
        ];
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Consultant::class, 'sponsor_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function registeredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_user_id');
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    /** Link valido: approvata, non ancora usato e non scaduto. */
    public function isUsable(): bool
    {
        return $this->status === 'approved' && filled($this->token) && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }
}
