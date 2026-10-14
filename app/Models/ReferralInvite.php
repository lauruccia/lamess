<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Invito referral con link personale: dall'invio alla registrazione (e al primo ordine) di una persona. */
class ReferralInvite extends Model
{
    public const CHANNELS = ['email' => 'Email', 'whatsapp' => 'WhatsApp', 'link' => 'Solo link'];

    /** Stato mostrato: gli stati salvati più «ordered» (registrato che ha già un ordine pagato). */
    public const STATUS_LABELS = [
        'created' => 'Creato', 'sent' => 'Inviato', 'opened' => 'Aperto', 'registered' => 'Registrato',
        'ordered' => 'Ha ordinato', 'expired' => 'Scaduto', 'failed' => 'Email non partita',
    ];

    /** Tono del badge nell'area utente. */
    public const STATUS_TONES = [
        'created' => 'mute', 'sent' => 'info', 'opened' => 'warn', 'registered' => 'ok', 'ordered' => 'ok', 'expired' => 'mute', 'failed' => 'err',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime', 'last_sent_at' => 'immutable_datetime', 'opened_at' => 'immutable_datetime',
            'last_opened_at' => 'immutable_datetime', 'registered_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
            'first_paid_at' => 'immutable_datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(Consultant::class);
    }

    public function registeredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_user_id');
    }

    /** Aggiunge `first_paid_at`: data del primo ordine pagato dell'utente registrato con questo invito. */
    public function scopeWithFirstPaidAt(Builder $query): Builder
    {
        return $query->select('referral_invites.*')->addSelect([
            'first_paid_at' => Order::query()->select('paid_at')
                ->whereColumn('orders.buyer_user_id', 'referral_invites.registered_user_id')
                ->whereIn('orders.status', Order::PAID_STATES)->whereNotNull('orders.paid_at')
                ->orderBy('orders.paid_at')->limit(1),
        ]);
    }

    /** Registrati che hanno almeno un ordine pagato. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->where('status', 'registered')->whereExists(
            fn ($q) => $q->selectRaw('1')->from('orders')->whereColumn('orders.buyer_user_id', 'referral_invites.registered_user_id')->whereIn('orders.status', Order::PAID_STATES)
        );
    }

    /** Registrati che non hanno ancora ordinato. */
    public function scopeRegisteredWithoutOrder(Builder $query): Builder
    {
        return $query->where('status', 'registered')->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from('orders')->whereColumn('orders.buyer_user_id', 'referral_invites.registered_user_id')->whereIn('orders.status', Order::PAID_STATES)
        );
    }

    /** Richiede la colonna `first_paid_at` (scope withFirstPaidAt). */
    public function displayStatus(): string
    {
        return $this->status === 'registered' && $this->getAttribute('first_paid_at') !== null ? 'ordered' : (string) $this->status;
    }

    public function displayLabel(): string
    {
        return self::STATUS_LABELS[$this->displayStatus()] ?? (string) $this->status;
    }

    public function displayTone(): string
    {
        return self::STATUS_TONES[$this->displayStatus()] ?? 'mute';
    }

    /** Chi è stato invitato, nel modo più leggibile disponibile. */
    public function recipientLabel(): string
    {
        return (string) ($this->recipient_name ?: $this->email ?: $this->phone ?: 'Link personale');
    }
}
