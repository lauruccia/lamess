<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Domain\Audit\AuditLog;
use App\Domain\Support\SupportException;
use App\Domain\Support\TicketService;
use App\Models\CommissionStatement;
use App\Models\Period;
use App\Models\Settlement;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Vita dell'estratto conto provvigionale (Reg. 5.2).
 *
 *  - alla chiusura del mese si pubblica un estratto per ogni incaricato con liquidazione;
 *  - dalla pubblicazione l’incaricato ha `lamess.dispute_days` giorni (default 10) per un reclamo circostanziato, aperto come ticket;
 *  - scaduto il termine senza reclamo l'estratto si considera accettato tacitamente (comando `lamess:estratti`);
 *  - l'amministratore decide il reclamo (accolto / respinto): l'eventuale correzione economica si fa con una rettifica nel mese aperto.
 */
final class StatementLifecycle
{
    /** Lunghezza minima del testo di un reclamo «circostanziato». */
    public const MIN_DISPUTE_LENGTH = 30;

    public function __construct(private readonly TicketService $tickets) {}

    public static function disputeDays(): int
    {
        return max(1, (int) config('lamess.dispute_days'));
    }

    /** @return int numero di estratti pubblicati ora (quelli già pubblicati non si toccano) */
    public function publish(Period $period, ?DateTimeInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());
        $deadline = $now->setTimezone('Europe/Rome')->addDays(self::disputeDays())->endOfDay()->setTimezone(config('app.timezone'));
        $n = 0;
        foreach (Settlement::where('period_id', $period->id)->pluck('consultant_id') as $consultantId) {
            $s = CommissionStatement::firstOrCreate(
                ['period_id' => $period->id, 'consultant_id' => $consultantId],
                ['published_at' => $now, 'dispute_deadline_at' => $deadline, 'status' => CommissionStatement::PUBLISHED],
            );
            $n += $s->wasRecentlyCreated ? 1 : 0;
        }
        if ($n > 0) {
            AuditLog::record('statements.published', "Estratti conto pubblicati per {$period->code}: {$n}", $period, ['estratti' => $n, 'giorni_per_reclamo' => self::disputeDays()]);
        }

        return $n;
    }

    /** L’incaricato apre un reclamo sul proprio estratto. */
    public function dispute(User $user, CommissionStatement $statement, string $text): Ticket
    {
        if ($user->consultant === null || (int) $statement->consultant_id !== (int) $user->consultant->id) {
            throw new SupportException('Non puoi contestare un estratto che non è tuo.');
        }
        if (! $statement->canBeDisputed()) {
            throw new SupportException($statement->status === CommissionStatement::PUBLISHED
                ? 'Il termine di '.self::disputeDays().' giorni è scaduto: l\'estratto è considerato accettato.'
                : 'Per questo estratto c\'è già un reclamo o è stato accettato.');
        }
        if (mb_strlen(trim($text)) < self::MIN_DISPUTE_LENGTH) {
            throw new SupportException('Il reclamo deve essere circostanziato: indica la voce contestata e il motivo (almeno '.self::MIN_DISPUTE_LENGTH.' caratteri).');
        }

        return DB::transaction(function () use ($user, $statement, $text): Ticket {
            $ticket = $this->tickets->open($user, 'statement_dispute', "Reclamo sull'estratto conto {$statement->period->code}", $text, $statement);
            $statement->update(['status' => CommissionStatement::DISPUTED, 'disputed_at' => now(), 'ticket_id' => $ticket->id]);
            AuditLog::record('statement.disputed', "Reclamo sull'estratto {$statement->period->code} di {$statement->consultant->code}", $statement, ['ticket' => $ticket->number]);

            return $ticket;
        });
    }

    /** Scaduto il termine senza reclamo: accettazione tacita. @return int estratti accettati */
    public function acceptDue(?DateTimeInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());
        $due = CommissionStatement::where('status', CommissionStatement::PUBLISHED)->where('dispute_deadline_at', '<', $now)->get();
        foreach ($due as $s) {
            $s->update(['status' => CommissionStatement::ACCEPTED, 'accepted_at' => $now]);
        }
        if ($due->isNotEmpty()) {
            AuditLog::record('statements.accepted', "Estratti accettati per decorrenza dei termini: {$due->count()}", null, ['estratti' => $due->pluck('id')->all()]);
        }

        return $due->count();
    }

    /** Decisione sul reclamo: chiude il ticket e registra l'esito. */
    public function resolve(CommissionStatement $statement, string $outcome, string $note, User $by): CommissionStatement
    {
        if ($statement->status !== CommissionStatement::DISPUTED) {
            throw new SupportException('Questo estratto non ha un reclamo da decidere.');
        }
        if (! in_array($outcome, ['upheld', 'rejected'], true)) {
            throw new SupportException('Esito non valido.');
        }
        if (trim($note) === '') {
            throw new SupportException('Motiva la decisione.');
        }
        DB::transaction(function () use ($statement, $outcome, $note, $by): void {
            $statement->update(['status' => CommissionStatement::RESOLVED, 'outcome' => $outcome, 'outcome_note' => $note, 'resolved_at' => now()]);
            if ($statement->ticket !== null) {
                $this->tickets->reply($statement->ticket, $by, ($outcome === 'upheld' ? 'Reclamo accolto. ' : 'Reclamo respinto. ').$note, true);
                $this->tickets->close($statement->ticket);
            }
        });
        AuditLog::record('statement.resolved', "Reclamo sull'estratto {$statement->period->code} di {$statement->consultant->code}: ".($outcome === 'upheld' ? 'accolto' : 'respinto'), $statement, [], $note);

        return $statement->refresh();
    }
}
