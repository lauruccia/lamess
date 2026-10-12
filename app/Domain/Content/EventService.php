<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Models\CorporateEvent;
use App\Models\EventRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Iscrizioni a eventi e corsi, con posti limitati e lista d'attesa. */
final class EventService
{
    public function isOpen(CorporateEvent $e): bool
    {
        if ($e->status !== 'published' || $e->starts_at->isPast()) {
            return false;
        }

        return $e->registration_deadline === null || $e->registration_deadline->isFuture();
    }

    public function register(CorporateEvent $e, User $user): EventRegistration
    {
        return DB::transaction(function () use ($e, $user): EventRegistration {
            $e = CorporateEvent::whereKey($e->id)->lockForUpdate()->firstOrFail();
            if (! $this->isOpen($e)) {
                throw new ContentException('Le iscrizioni a questo appuntamento sono chiuse.');
            }
            if (! Audience::allows($e->audience, $user)) {
                throw new ContentException('Questo appuntamento non è rivolto al tuo profilo.');
            }
            $existing = EventRegistration::where('corporate_event_id', $e->id)->where('user_id', $user->id)->first();
            if ($existing !== null && $existing->status !== 'cancelled') {
                throw new ContentException('Sei già iscritto.');
            }
            $full = $e->capacity !== null && $e->registeredCount() >= $e->capacity;
            $data = ['status' => $full ? 'waitlist' : 'registered', 'cancelled_at' => null];

            return $existing !== null ? tap($existing)->update($data) : EventRegistration::create($data + ['corporate_event_id' => $e->id, 'user_id' => $user->id]);
        });
    }

    /** Annulla l'iscrizione; se si libera un posto sale il primo della lista d'attesa. Restituisce chi è stato promosso. */
    public function cancel(CorporateEvent $e, User $user): ?EventRegistration
    {
        return DB::transaction(function () use ($e, $user): ?EventRegistration {
            CorporateEvent::whereKey($e->id)->lockForUpdate()->firstOrFail();
            $r = EventRegistration::where('corporate_event_id', $e->id)->where('user_id', $user->id)->whereIn('status', ['registered', 'waitlist'])->first();
            if ($r === null) {
                throw new ContentException('Non risulti iscritto.');
            }
            if ($e->starts_at->isPast()) {
                throw new ContentException('L\'appuntamento è già iniziato.');
            }
            $wasSeated = $r->status === 'registered';
            $r->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $wasSeated ? $this->promoteNext($e) : null;
        });
    }

    /** Riempie i posti liberi con la lista d'attesa, nell'ordine di iscrizione. */
    public function promoteNext(CorporateEvent $e): ?EventRegistration
    {
        $first = null;
        while ($e->capacity === null || $e->registeredCount() < $e->capacity) {
            $next = EventRegistration::where('corporate_event_id', $e->id)->where('status', 'waitlist')->orderBy('id')->first();
            if ($next === null) {
                break;
            }
            $next->update(['status' => 'registered']);
            $first ??= $next;
        }

        return $first;
    }

    public function mine(CorporateEvent $e, User $user): ?EventRegistration
    {
        return EventRegistration::where('corporate_event_id', $e->id)->where('user_id', $user->id)->whereIn('status', ['registered', 'waitlist'])->first();
    }
}
