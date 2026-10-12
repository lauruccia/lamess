<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Customers\CustomerService;
use App\Domain\Network\NetworkService;
use App\Models\AnnualFee;
use App\Models\IvdContract;
use App\Notifications\ContractTerminated;
use App\Notifications\FeeReminder;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Canone annuale (RF-020…023): il primo anno è gratuito, dal secondo si paga alla scadenza della lettera.
 * Alla scadenza senza pagamento (dopo l'eventuale periodo di grazia, OPEN-24) la lettera si risolve.
 */
final class FeeService
{
    public function __construct(private readonly NetworkService $network, private readonly CustomerService $customers) {}

    /** @return array{renewed:int,fees_created:int,reminders:int,terminated:int} */
    public function process(?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable;
        $out = ['renewed' => 0, 'fees_created' => 0, 'reminders' => 0, 'terminated' => 0];
        $reminderDays = (array) config('lamess.annual_fee_reminder_days');
        $window = $reminderDays === [] ? 0 : max($reminderDays);
        $grace = (int) config('lamess.annual_fee_grace_days');

        foreach (IvdContract::with('consultant.user')->where('status', 'accepted')->whereNotNull('expires_at')->get() as $c) {
            $consultant = $c->consultant;
            if ($consultant === null || $consultant->status === 'terminated') {
                continue;
            }
            $nextYear = $c->term_index + 1;
            $feeRequired = (bool) config('lamess.annual_fee_enabled', true) && $nextYear >= (int) config('lamess.annual_fee_first_paid_year');
            $daysLeft = (int) floor(($c->expires_at->getTimestamp() - $now->getTimestamp()) / 86400);
            $fee = AnnualFee::where('consultant_id', $consultant->id)->where('year_index', $nextYear)->first();

            if ($feeRequired && $fee === null && $daysLeft <= $window) {
                $fee = AnnualFee::create([
                    'consultant_id' => $consultant->id, 'year_index' => $nextYear, 'amount' => config('lamess.annual_fee_amount'),
                    'due_at' => $c->expires_at, 'status' => 'due', 'reminders_sent' => [],
                ]);
                $out['fees_created']++;
            }
            if ($fee !== null && $fee->status === 'due') {
                $sent = $fee->reminders_sent ?? [];
                $reached = array_filter($reminderDays, fn ($d) => $daysLeft <= $d);
                if ($daysLeft >= 0 && $reached !== []) {
                    $d = min($reached);
                    if (! in_array($d, $sent, true)) {
                        // un solo promemoria: quello della soglia più vicina; le soglie superiori non si recuperano
                        $consultant->user?->notify(new FeeReminder($fee, $daysLeft));
                        $fee->update(['reminders_sent' => array_values(array_unique(array_merge($sent, array_filter($reminderDays, fn ($x) => $x >= $d))))]);
                        $out['reminders']++;
                    }
                }
            }

            if ($now >= $c->expires_at) {
                if (! $feeRequired || ($fee !== null && $fee->status === 'paid')) {
                    $c->update(['expires_at' => $c->expires_at->modify('+'.(int) config('lamess.ivd_term_months').' months'), 'term_index' => $nextYear]);
                    $out['renewed']++;
                } elseif ($now >= $c->expires_at->modify("+{$grace} days")) {
                    $this->terminate($c, $fee);
                    $out['terminated']++;
                }
            }
        }

        return $out;
    }

    public function markPaid(AnnualFee $fee, string $method = 'transfer'): AnnualFee
    {
        if ($fee->status !== 'due') {
            throw new OnboardingException('Canone già regolato o annullato.');
        }
        $fee->update(['status' => 'paid', 'paid_at' => now(), 'method' => $method]);

        return $fee;
    }

    private function terminate(IvdContract $c, ?AnnualFee $fee): void
    {
        DB::transaction(function () use ($c, $fee): void {
            $consultant = $c->consultant;
            $this->network->terminate($consultant, 'Mancato pagamento del canone annuale');
            $c->update(['status' => 'expired']);
            $fee?->update(['status' => 'void']);
            $sponsor = $this->network->effectiveSponsor($consultant);
            $this->customers->ensureCustomer($c->user, $sponsor);
            $c->user->notify(new ContractTerminated('Mancato pagamento del canone annuale'));
        });
    }
}
