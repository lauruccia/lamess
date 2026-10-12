<?php

declare(strict_types=1);

namespace App\Domain\Support;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Models\CommissionReceipt;
use App\Models\CommissionStatement;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Assistenza: apertura, risposte (anche note interne), chiusura e riapertura dei ticket. */
final class TicketService
{
    public function open(User $user, string $category, string $subject, string $body, ?Model $related = null): Ticket
    {
        if (! isset(Ticket::CATEGORIES[$category])) {
            throw new SupportException('Categoria non valida.');
        }
        $subject = trim($subject);
        $body = trim($body);
        if ($subject === '' || $body === '') {
            throw new SupportException('Oggetto e messaggio sono obbligatori.');
        }

        return DB::transaction(function () use ($user, $category, $subject, $body, $related): Ticket {
            $t = Ticket::create([
                'user_id' => $user->id, 'consultant_id' => $user->consultant?->id, 'category' => $category, 'subject' => mb_substr($subject, 0, 190),
                'status' => Ticket::OPEN, 'last_message_at' => now(),
                'related_type' => $related?->getMorphClass(), 'related_id' => $related?->getKey(),
            ]);
            $t->update(['number' => 'TK-'.str_pad((string) $t->id, 6, '0', STR_PAD_LEFT)]);
            TicketMessage::create(['ticket_id' => $t->id, 'user_id' => $user->id, 'is_staff' => false, 'body' => $body]);
            AuditLog::record('ticket.opened', "Aperto il ticket {$t->number}: ".Ticket::CATEGORIES[$category], $t, ['categoria' => $category]);

            return $t;
        });
    }

    /** Risposta del richiedente (is_staff = false) o dell'assistenza (true). Una nota interna non è visibile al richiedente. */
    public function reply(Ticket $ticket, User $by, string $body, bool $asStaff, bool $internal = false): TicketMessage
    {
        $body = trim($body);
        if ($body === '') {
            throw new SupportException('Scrivi un messaggio.');
        }
        if ($ticket->status === Ticket::CLOSED) {
            throw new SupportException('Il ticket è chiuso: riaprilo per rispondere.');
        }
        if ($internal && ! $asStaff) {
            throw new SupportException('Solo l\'assistenza scrive note interne.');
        }

        return DB::transaction(function () use ($ticket, $by, $body, $asStaff, $internal): TicketMessage {
            $m = TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $by->id, 'is_staff' => $asStaff, 'is_internal' => $internal, 'body' => $body]);
            if (! $internal) {
                $ticket->update(['status' => $asStaff ? Ticket::ANSWERED : Ticket::OPEN, 'last_message_at' => now()]);
            }

            return $m;
        });
    }

    public function close(Ticket $ticket, ?string $note = null): Ticket
    {
        if ($ticket->status === Ticket::CLOSED) {
            return $ticket;
        }
        $ticket->update(['status' => Ticket::CLOSED, 'closed_at' => now()]);
        AuditLog::record('ticket.closed', "Chiuso il ticket {$ticket->number}", $ticket, ['da' => Actor::label()], $note);

        return $ticket;
    }

    public function reopen(Ticket $ticket): Ticket
    {
        if ($ticket->status !== Ticket::CLOSED) {
            return $ticket;
        }
        if (in_array($ticket->related_type, [CommissionStatement::class, CommissionReceipt::class], true)) {
            throw new SupportException('Un reclamo su estratto conto o ricevuta deciso non si riapre: se ci sono fatti nuovi aprine uno nuovo entro i termini.');
        }
        $ticket->update(['status' => Ticket::OPEN, 'closed_at' => null, 'last_message_at' => now()]);
        AuditLog::record('ticket.reopened', "Riaperto il ticket {$ticket->number}", $ticket);

        return $ticket;
    }

    public function assign(Ticket $ticket, ?string $label): Ticket
    {
        $ticket->update(['assigned_to' => $label]);

        return $ticket;
    }
}
