<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\DocumentNumbers;
use App\Domain\Support\SupportException;
use App\Domain\Support\TicketService;
use App\Models\CommissionReceipt;
use App\Models\Payout;
use App\Models\Ticket;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ricevute provvigionali (Reg. 5.1): il Consorzio le emette per conto dell'incaricato a ogni pagamento eseguito.
 * Dalla pubblicazione l'incaricato ha `lamess.dispute_days` giorni per segnalare anomalie (come per l'estratto conto).
 * Disattivate di default (`lamess.fiscal.receipts_enabled`) finché il commercialista non ha validato i parametri fiscali.
 */
final class ReceiptService
{
    public function __construct(private readonly TicketService $tickets) {}

    public static function enabled(): bool
    {
        return (bool) config('lamess.fiscal.receipts_enabled');
    }

    /** Emette la ricevuta di un pagamento (una sola per pagamento). Null se le ricevute sono spente. */
    public function issue(Payout $payout): ?CommissionReceipt
    {
        if (! self::enabled()) {
            return null;
        }
        if (($existing = CommissionReceipt::where('payout_id', $payout->id)->first()) !== null) {
            return $existing;
        }
        $c = $payout->consultant;
        $regime = $c->fiscal_regime ?? 'occasional';
        $year = (int) ($payout->fiscal_year ?? CarbonImmutable::now('Europe/Rome')->format('Y'));
        // importi autoritativi dal pagamento; le basi di calcolo si ricostruiscono con gli stessi parametri
        $f = FiscalCalculator::compute($regime, $payout->gross(), FiscalCalculator::ytdGross($c, $year, $payout->id), (string) $payout->fee_amount);
        $now = CarbonImmutable::now();

        $receipt = CommissionReceipt::create([
            'number' => DocumentNumbers::next('RP', (int) $now->setTimezone('Europe/Rome')->format('Y')), 'consultant_id' => $c->id, 'payout_id' => $payout->id, 'regime' => $regime,
            'issued_at' => $now, 'gross' => $payout->gross(), 'taxable_base' => $f['taxable_base'], 'withholding' => (string) $payout->withholding_amount,
            'inps_base' => $f['inps_base'], 'inps_total' => $f['inps_total'], 'inps_worker' => (string) $payout->inps_amount, 'fee' => (string) $payout->fee_amount,
            'net' => (string) $payout->amount, 'params' => FiscalCalculator::params(),
            'dispute_deadline_at' => $now->setTimezone('Europe/Rome')->addDays(StatementLifecycle::disputeDays())->endOfDay()->setTimezone(config('app.timezone')),
            'status' => 'published',
        ]);
        AuditLog::record('receipt.issued', "Ricevuta {$receipt->number} emessa per {$c->code}: lordo {$receipt->gross} €, netto {$receipt->net} €", $receipt);

        return $receipt;
    }

    public function dispute(User $user, CommissionReceipt $receipt, string $text): Ticket
    {
        if ($user->consultant === null || (int) $receipt->consultant_id !== (int) $user->consultant->id) {
            throw new SupportException('Non puoi segnalare anomalie su una ricevuta che non è tua.');
        }
        if (! $receipt->canBeDisputed()) {
            throw new SupportException($receipt->status === 'published' ? 'Il termine per le segnalazioni è scaduto.' : 'Per questa ricevuta c\'è già una segnalazione o è stata accettata.');
        }
        if (mb_strlen(trim($text)) < StatementLifecycle::MIN_DISPUTE_LENGTH) {
            throw new SupportException('La segnalazione deve essere circostanziata (almeno '.StatementLifecycle::MIN_DISPUTE_LENGTH.' caratteri).');
        }

        return DB::transaction(function () use ($user, $receipt, $text): Ticket {
            $ticket = $this->tickets->open($user, 'receipt_dispute', "Segnalazione sulla ricevuta {$receipt->number}", $text, $receipt);
            $receipt->update(['status' => 'disputed', 'disputed_at' => now(), 'ticket_id' => $ticket->id]);
            AuditLog::record('receipt.disputed', "Segnalazione sulla ricevuta {$receipt->number}", $receipt, ['ticket' => $ticket->number]);

            return $ticket;
        });
    }

    /** @return int ricevute accettate per decorrenza dei termini */
    public function acceptDue(?DateTimeInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());

        return CommissionReceipt::where('status', 'published')->where('dispute_deadline_at', '<', $now)->update(['status' => 'accepted', 'accepted_at' => $now]);
    }

    public function resolve(CommissionReceipt $receipt, string $outcome, string $note, User $by): CommissionReceipt
    {
        if ($receipt->status !== 'disputed') {
            throw new SupportException('Questa ricevuta non ha una segnalazione da decidere.');
        }
        if (! in_array($outcome, ['upheld', 'rejected'], true) || trim($note) === '') {
            throw new SupportException('Esito e motivazione sono obbligatori.');
        }
        DB::transaction(function () use ($receipt, $outcome, $note, $by): void {
            $receipt->update(['status' => 'resolved', 'outcome' => $outcome, 'outcome_note' => $note, 'resolved_at' => now()]);
            if ($receipt->ticket !== null) {
                $this->tickets->reply($receipt->ticket, $by, ($outcome === 'upheld' ? 'Segnalazione accolta. ' : 'Segnalazione respinta. ').$note, true);
                $this->tickets->close($receipt->ticket);
            }
        });
        AuditLog::record('receipt.resolved', "Segnalazione sulla ricevuta {$receipt->number}: ".($outcome === 'upheld' ? 'accolta' : 'respinta'), $receipt, [], $note);

        return $receipt->refresh();
    }

    public function pdf(CommissionReceipt $receipt): string
    {
        return Pdf::loadView('pdf.receipt', ['r' => $receipt->loadMissing('consultant', 'payout.period'), 'seller' => config('lamess.seller')])->output();
    }
}
