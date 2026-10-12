<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Models\ComplianceCase;
use App\Models\Consultant;
use App\Models\Sanction;
use App\Models\Ticket;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Casi di conformità e sanzioni. Gli effetti sono automatici e reversibili (tranne la cessazione):
 *  - richiamo: solo registrato;
 *  - blocco dei compensi: accende `bonus_hold`, che ferma liquidazioni e prelievi;
 *  - sospensione: porta l’incaricato in stato «sospeso»;
 *  - cessazione: cessa l’incaricato con le regole della rete (diretti ricollegati).
 * Una sanzione a tempo scade da sola (comando `lamess:sanzioni`) e ripristina lo stato precedente.
 */
final class ComplianceService
{
    public function __construct(private readonly NetworkService $network) {}

    public function openCase(Consultant $c, string $category, string $description, ?Ticket $ticket = null): ComplianceCase
    {
        if (! array_key_exists($category, ComplianceCase::CATEGORIES)) {
            throw new ComplianceException('Categoria non valida.');
        }
        if (mb_strlen(trim($description)) < 20) {
            throw new ComplianceException('Descrivi i fatti in almeno 20 caratteri.');
        }

        return DB::transaction(function () use ($c, $category, $description, $ticket): ComplianceCase {
            $case = ComplianceCase::create([
                'consultant_id' => $c->id, 'ticket_id' => $ticket?->id, 'category' => $category, 'description' => trim($description),
                'opened_by' => Actor::label(), 'opened_at' => now(),
            ]);
            $case->update(['number' => 'CC-'.str_pad((string) $case->id, 5, '0', STR_PAD_LEFT)]);
            AuditLog::record('compliance.opened', "Aperto il caso {$case->number} su {$c->code}", $case, ['categoria' => $category]);

            return $case;
        });
    }

    public function investigate(ComplianceCase $case): void
    {
        $this->assertOpen($case);
        $case->update(['status' => 'investigating']);
        AuditLog::record('compliance.investigating', "Caso {$case->number} in istruttoria", $case);
    }

    public function dismiss(ComplianceCase $case, string $note): void
    {
        $this->assertOpen($case);
        $this->requireText($note, 'la motivazione');
        $case->update(['status' => 'dismissed', 'decided_by' => Actor::label(), 'decision_note' => trim($note), 'closed_at' => now()]);
        AuditLog::record('compliance.dismissed', "Caso {$case->number} archiviato", $case, [], $note);
    }

    /** Applica una sanzione, opzionalmente a chiusura di un caso. */
    public function sanction(Consultant $c, string $type, string $reason, ?ComplianceCase $case = null, ?DateTimeInterface $endsAt = null): Sanction
    {
        if (! array_key_exists($type, Sanction::TYPES)) {
            throw new ComplianceException('Tipo di sanzione non valido.');
        }
        $this->requireText($reason, 'la motivazione');
        if ($case !== null && $case->consultant_id !== $c->id) {
            throw new ComplianceException('Il caso riguarda un altro incaricato.');
        }
        if ($case !== null && ! $case->isOpen() && $case->status !== 'sanctioned') {
            throw new ComplianceException('Il caso è stato archiviato: apri un nuovo caso.');
        }
        if ($c->status === 'terminated') {
            throw new ComplianceException('L’incaricato è già cessato.');
        }
        if ($endsAt !== null && ($type === 'warning' || $type === 'termination')) {
            throw new ComplianceException('Richiamo e cessazione non hanno scadenza.');
        }
        if ($endsAt !== null && $endsAt <= now()) {
            throw new ComplianceException('La scadenza deve essere nel futuro.');
        }

        return DB::transaction(function () use ($c, $type, $reason, $case, $endsAt): Sanction {
            $by = Actor::label();
            $holds = Sanction::where('consultant_id', $c->id)->where('status', 'active')->where('type', 'commission_hold')->get();
            $alreadyHeld = (bool) $c->bonus_hold && ($holds->isEmpty() || $holds->contains('had_hold_before', true));
            $s = Sanction::create([
                'compliance_case_id' => $case?->id, 'consultant_id' => $c->id, 'type' => $type, 'reason' => trim($reason),
                'starts_at' => now(), 'ends_at' => $endsAt, 'applied_by' => $by, 'had_hold_before' => $alreadyHeld,
            ]);
            try {
                match ($type) {
                    'commission_hold' => $c->update(['bonus_hold' => true]),
                    'suspension' => $this->suspend($c, $reason, $by),
                    'termination' => $this->network->terminate($c, 'Sanzione: '.trim($reason), null, $by),
                    default => null,
                };
            } catch (NetworkException $e) {
                throw new ComplianceException($e->getMessage(), 0, $e);
            }
            $case?->update(['status' => 'sanctioned', 'decided_by' => $by, 'decision_note' => $case->decision_note ?: trim($reason), 'closed_at' => $case->closed_at ?? now()]);
            AuditLog::record('compliance.sanctioned', Sanction::TYPES[$type]." a {$c->code}", $s, ['tipo' => $type, 'scadenza' => $endsAt?->format('Y-m-d H:i')], $reason);

            return $s;
        });
    }

    public function lift(Sanction $s, string $reason): void
    {
        $this->end($s, 'lifted', $reason, Actor::label());
    }

    /** Chiude le sanzioni a tempo scadute. @return int quante */
    public function expireDue(): int
    {
        $n = 0;
        foreach (Sanction::where('status', 'active')->whereNotNull('ends_at')->where('ends_at', '<=', now())->get() as $s) {
            $this->end($s, 'expired', 'Scadenza naturale della sanzione', 'sistema');
            $n++;
        }

        return $n;
    }

    /** @return Collection<int, Sanction> */
    public function active(Consultant $c)
    {
        return Sanction::where('consultant_id', $c->id)->where('status', 'active')->orderBy('id')->get();
    }

    private function suspend(Consultant $c, string $reason, string $by): void
    {
        if ($c->status !== 'active') {
            throw new ComplianceException("Si può sospendere solo un incaricato attivo (stato attuale: {$c->status}).");
        }
        $this->network->changeStatus($c, 'suspended', 'Sanzione: '.trim($reason), null, $by);
    }

    private function end(Sanction $s, string $to, string $reason, string $by): void
    {
        if ($s->status !== 'active') {
            throw new ComplianceException('La sanzione non è più attiva.');
        }
        if ($s->type === 'termination') {
            throw new ComplianceException('La cessazione non si revoca: l’incaricato si riammette con una nuova adesione.');
        }
        $this->requireText($reason, 'la motivazione');
        DB::transaction(function () use ($s, $to, $reason, $by): void {
            $s->update(['status' => $to, 'lifted_at' => now(), 'lifted_by' => $by, 'lift_reason' => trim($reason)]);
            $c = $s->consultant()->first();
            $others = Sanction::where('consultant_id', $c->id)->where('status', 'active');
            if ($s->type === 'commission_hold' && ! $s->had_hold_before && ! (clone $others)->where('type', 'commission_hold')->exists()) {
                $c->update(['bonus_hold' => false]);
            }
            if ($s->type === 'suspension' && $c->status === 'suspended' && ! (clone $others)->where('type', 'suspension')->exists()) {
                $this->network->changeStatus($c, 'active', 'Fine sanzione: '.trim($reason), null, $by);
            }
            AuditLog::record($to === 'expired' ? 'compliance.expired' : 'compliance.lifted', Sanction::TYPES[$s->type]." di {$c->code}: ".($to === 'expired' ? 'scaduta' : 'revocata'), $s, [], $reason);
        });
    }

    private function assertOpen(ComplianceCase $case): void
    {
        if (! $case->isOpen()) {
            throw new ComplianceException('Il caso è già chiuso.');
        }
    }

    private function requireText(string $t, string $what): void
    {
        if (mb_strlen(trim($t)) < 5) {
            throw new ComplianceException("Indica {$what}.");
        }
    }
}
