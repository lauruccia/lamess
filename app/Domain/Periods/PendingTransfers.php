<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Support\PeriodKey;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PeriodResolver;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Period;
use App\Models\PvMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Controllo dei bonifici in sospeso a fine mese, da fare PRIMA del runtime ufficiale.
 *
 * Per ogni ordine in attesa di bonifico l'admin decide:
 *  - conferma nel mese: il bonifico è arrivato, le provvigioni valgono nel mese dell'ordine (l'incasso e la fattura restano alla data reale);
 *  - sposta al mese successivo: l'ordine resta in attesa e conterà nel mese in cui verrà confermato;
 *  - annulla: l'ordine decade, i punti si annullano, magazzino e sconti si liberano.
 * Il runtime ufficiale di un mese già finito non parte finché resta un bonifico non deciso.
 */
final class PendingTransfers
{
    public function __construct(private readonly OrderService $orders, private readonly PeriodResolver $resolver) {}

    /** Bonifici ancora in attesa che riguardano il mese: con righe PV in attesa nel mese o creati entro la fine del mese, e non rimandati più avanti. */
    public function query(Period $period): Builder
    {
        $ids = PvMovement::where('period_id', $period->id)->where('status', 'pending_payment')->whereNotNull('order_id')->pluck('order_id');

        return Order::query()->where('status', 'pending_payment')->where('payment_method', 'transfer')
            ->where(fn (Builder $q) => $q->whereIn('id', $ids)->orWhere('created_at', '<=', $period->ends_at))
            ->where(fn (Builder $q) => $q->whereNull('deferred_to_period')->orWhere('deferred_to_period', '<=', $period->code));
    }

    public function count(Period $period): int
    {
        return $this->query($period)->count();
    }

    /** Blocca il runtime ufficiale di un mese già finito se restano bonifici da decidere. */
    public function assertResolved(Period $period): void
    {
        if (now()->lt($period->ends_at)) {
            return;   // mese ancora in corso: gli ordini possono ancora arrivare
        }
        $n = $this->count($period);
        if ($n > 0) {
            throw new PeriodStateException("Ci sono {$n} bonifici in attesa nel mese {$period->code}: per ciascuno scegli se confermarlo nel mese, spostarlo al mese successivo o annullarlo, in «Bonifici in sospeso», prima del runtime ufficiale.");
        }
    }

    /** Il bonifico è arrivato: l'ordine vale nel mese $period (data dell'ordine), l'incasso resta alla data di oggi. */
    public function confirmInPeriod(Order $order, Period $period, string $reason, ?string $reference = null): Order
    {
        $this->assertEditable($order, $period);
        $competence = $this->competenceAt($order, $period);
        $paid = $this->orders->markPaid($order, $reference, null, $competence);
        $note = "Bonifico confermato con competenza {$period->code} ({$competence->setTimezone(PeriodKey::timezone())->format('d/m/Y H:i')}): {$reason}";
        OrderEvent::log($paid, 'competence_set', $note, true);
        AuditLog::record('period.transfer_confirmed', "Ordine {$order->number}: bonifico confermato nel mese {$period->code}", $period, ['ordine' => $order->number], $reason);

        return $paid;
    }

    /** Rimanda l'ordine al mese successivo: resta in attesa e la chiusura del mese non lo annulla. */
    public function deferToNext(Order $order, Period $period, string $reason): Order
    {
        $this->assertEditable($order, $period);
        $target = $this->resolver->forDate(PeriodKey::parse($period->code)->next()->start());

        DB::transaction(function () use ($order, $period, $target, $reason): void {
            PvMovement::where('order_id', $order->id)->where('status', 'pending_payment')->update(['period_id' => $target->id]);
            $order->update(['deferred_to_period' => $target->code]);
            OrderEvent::log($order, 'deferred', "Bonifico rimandato al mese {$target->code}: {$reason}", true);
            AuditLog::record('period.transfer_deferred', "Ordine {$order->number}: bonifico rimandato da {$period->code} a {$target->code}", $period, ['ordine' => $order->number], $reason);
        });

        return $order->refresh();
    }

    /** Il bonifico non è arrivato: ordine annullato (come l'annullo automatico alla chiusura, ma deciso a mano e con motivo). */
    public function cancel(Order $order, Period $period, string $reason): Order
    {
        $this->assertEditable($order, $period);
        $order = $this->orders->hasKMoneyToRefund($order) ? $this->orders->refundKMoneyAndCancel($order) : $this->orders->cancel($order);
        OrderEvent::log($order, 'cancelled', "Annullato dal controllo bonifici di {$period->code}: {$reason}", true);
        AuditLog::record('period.transfer_cancelled', "Ordine {$order->number}: bonifico annullato nel controllo del mese {$period->code}", $period, ['ordine' => $order->number], $reason);

        return $order;
    }

    /** Il momento che decide il mese: la data dell'ordine, riportata dentro il mese se ne fosse fuori. */
    public function competenceAt(Order $order, Period $period): CarbonImmutable
    {
        $start = CarbonImmutable::instance($period->starts_at);
        $end = CarbonImmutable::instance($period->ends_at);
        $created = CarbonImmutable::instance($order->created_at);

        return $created->lt($start) ? $start : ($created->gte($end) ? $end->subSecond() : $created);
    }

    private function assertEditable(Order $order, Period $period): void
    {
        if ($period->isClosed()) {
            throw new PeriodStateException("Il mese {$period->code} è già chiuso: il bonifico non si può più decidere da qui.");
        }
        if ($order->status !== 'pending_payment' || $order->payment_method !== 'transfer') {
            throw new PeriodStateException("L'ordine {$order->number} non è un bonifico in attesa di pagamento.");
        }
        if (trim((string) ($order->deferred_to_period ?? '')) > $period->code) {
            throw new PeriodStateException("L'ordine {$order->number} è già stato rimandato al mese {$order->deferred_to_period}.");
        }
    }
}
