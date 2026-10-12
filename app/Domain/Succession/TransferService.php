<?php

declare(strict_types=1);

namespace App\Domain\Succession;

use App\Domain\Access\Actor;
use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\Num;
use App\Domain\Payouts\PayoutService;
use App\Domain\Wallet\WalletService;
use App\Models\AccountTransfer;
use App\Models\ComplianceCase;
use App\Models\Consultant;
use App\Models\Sanction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cessione del codice attività e dell'account (Reg. 2.10.c e 9.1):
 * richiesta → pagamento preventivo della fee (50 €) → approvazione discrezionale e inoppugnabile → cambio di intestatario.
 */
final class TransferService
{
    public function __construct(private readonly AccountHolderService $holders, private readonly PayoutService $payouts, private readonly WalletService $wallet) {}

    public function request(Consultant $c, User $assignor, string $assigneeEmail): AccountTransfer
    {
        if ($c->user_id !== $assignor->id) {
            throw new SuccessionException('Puoi cedere solo il tuo codice.');
        }
        if ($c->status !== 'active') {
            throw new SuccessionException('Solo un codice attivo si può cedere.');
        }
        if (AccountTransfer::where('consultant_id', $c->id)->whereIn('status', AccountTransfer::OPEN)->exists()) {
            throw new SuccessionException('C\'è già una richiesta di cessione in corso.');
        }
        if (Sanction::where('consultant_id', $c->id)->where('status', 'active')->exists() || ComplianceCase::where('consultant_id', $c->id)->whereIn('status', ComplianceCase::OPEN_STATUSES)->exists()) {
            throw new SuccessionException('Non si può cedere un codice con provvedimenti o verifiche di conformità in corso.');
        }
        $assignee = User::whereRaw('lower(email) = ?', [mb_strtolower(trim($assigneeEmail))])->first();
        if ($assignee === null) {
            throw new SuccessionException('Non c\'è nessun utente registrato con questa email: il cessionario deve prima registrarsi sul portale e compilare i suoi dati.');
        }
        if ($assignee->id === $assignor->id) {
            throw new SuccessionException('Il cessionario deve essere un\'altra persona.');
        }
        if (($problem = $this->holders->assigneeProblem($assignee, $c)) !== null) {
            throw new SuccessionException($problem);
        }

        return DB::transaction(function () use ($c, $assignor, $assignee): AccountTransfer {
            $t = AccountTransfer::create([
                'consultant_id' => $c->id, 'assignor_user_id' => $assignor->id, 'assignee_user_id' => $assignee->id,
                'fee_amount' => (string) config('lamess.transfer_fee'), 'requested_at' => now(),
            ]);
            $t->update(['number' => 'CE-'.str_pad((string) $t->id, 5, '0', STR_PAD_LEFT)]);
            AuditLog::record('transfer.requested', "Richiesta di cessione {$t->number} del codice {$c->code}", $t, ['fee' => (string) $t->fee_amount]);

            return $t;
        });
    }

    /** Il richiedente ritira la richiesta finché non è stata approvata o respinta. */
    public function cancel(AccountTransfer $t, User $by): void
    {
        if ($t->assignor_user_id !== $by->id || ! $t->isOpen()) {
            throw new SuccessionException('Non puoi annullare questa richiesta.');
        }
        $t->update(['status' => 'cancelled', 'decided_at' => now(), 'decided_by' => $by->email]);
        AuditLog::record('transfer.cancelled', "Richiesta di cessione {$t->number} annullata dal richiedente", $t);
    }

    public function markFeePaid(AccountTransfer $t, string $method = 'transfer'): void
    {
        if ($t->status !== 'awaiting_payment') {
            throw new SuccessionException('La fee è già stata registrata o la richiesta è chiusa.');
        }
        $t->update(['status' => 'paid', 'fee_method' => $method, 'fee_paid_at' => now(), 'fee_marked_by' => Actor::label()]);
        AuditLog::record('transfer.fee_paid', "Fee di {$t->fee_amount} € registrata per {$t->number}", $t, ['metodo' => $method]);
    }

    /** Saldo non ancora pagato: liquidazioni aperte più wallet. */
    public function unpaidBalance(Consultant $c): string
    {
        return (string) Num::money($this->payouts->openBalance($c->id)->plus($this->wallet->balance($c->id)));
    }

    public function approve(AccountTransfer $t, bool $balanceFollowsAccount, string $note = ''): AccountTransfer
    {
        if ($t->status !== 'paid') {
            throw new SuccessionException($t->status === 'awaiting_payment' ? 'La fee preventiva non risulta ancora pagata.' : 'La richiesta non è più da decidere.');
        }
        $c = $t->consultant()->firstOrFail();
        if ($c->status !== 'active') {
            throw new SuccessionException("Il codice non è più attivo (stato: {$c->status}).");
        }
        $balance = $this->unpaidBalance($c);
        if (Num::of($balance)->isGreaterThan(Num::zero()) && ! $balanceFollowsAccount) {
            throw new SuccessionException("Ci sono {$balance} € maturati e non ancora pagati: liquidali al cedente prima di approvare, oppure conferma che passano al nuovo intestatario.");
        }

        return DB::transaction(function () use ($t, $c, $balance, $balanceFollowsAccount, $note): AccountTransfer {
            $contract = $this->holders->swap($c, $t->assignee()->firstOrFail(), 'transfer', "Cessione {$t->number}", Actor::label(), $t->assignor()->first());
            $t->update([
                'status' => 'approved', 'decided_at' => now(), 'decided_by' => Actor::label(), 'decision_note' => $note ?: null,
                'balance_at_decision' => $balance, 'balance_follows_account' => $balanceFollowsAccount, 'new_contract_id' => $contract->id,
            ]);
            AuditLog::record('transfer.approved', "Cessione {$t->number} approvata: il codice {$c->code} passa a un nuovo intestatario", $t, ['saldo' => $balance, 'saldo_segue' => $balanceFollowsAccount], $note ?: null);

            return $t->refresh();
        });
    }

    /** Diniego: discrezionale, non serve comunicarne il motivo (Reg. 2.10.c). */
    public function reject(AccountTransfer $t, string $internalNote = ''): void
    {
        if (! $t->isOpen()) {
            throw new SuccessionException('La richiesta non è più da decidere.');
        }
        $t->update(['status' => 'rejected', 'decided_at' => now(), 'decided_by' => Actor::label(), 'decision_note' => $internalNote ?: null]);
        AuditLog::record('transfer.rejected', "Cessione {$t->number} respinta", $t, [], $internalNote ?: null);
    }
}
