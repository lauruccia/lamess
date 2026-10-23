<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Billing\InvoiceService;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use App\Domain\Ledger\PvLedger;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Product;
use App\Models\PvMovement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resi: nota di credito + movimento PV negativo (RF-045, RF-053).
 * Periodo del movimento originale ancora aperto → storno nello stesso periodo;
 * periodo chiuso → storno nel mese corrente (il motore recupera i compensi, D11).
 */
final class ReturnService
{
    public function __construct(private readonly InvoiceService $invoices, private readonly PeriodResolver $periods) {}

    /**
     * @param  array<int,int>  $qtyByItem  order_item_id => quantità resa
     * @param  bool  $refundShipping  restituisce anche le spese di spedizione (una sola volta per ordine, OPEN-33)
     * @param  bool  $restock  rimette in giacenza le quantità rese (i componenti dei kit non si scalano, quindi non si restituiscono)
     */
    public function refund(Order $order, array $qtyByItem, string $reason, bool $restock = false, bool $refundShipping = false): CreditNote
    {
        if (! in_array($order->status, Order::PAID_STATES, true)) {
            throw new OrderException('Si può rendere solo un ordine pagato.');
        }
        $qtyByItem = array_filter($qtyByItem, fn ($q) => (int) $q > 0);
        $shipLeft = Num::of($order->shipping_net)->minus($order->shipping_refunded_net);
        if ($refundShipping && ! $shipLeft->isPositive()) {
            throw new OrderException('Le spese di spedizione di questo ordine sono già state rimborsate (o non c\'erano).');
        }
        if ($qtyByItem === [] && ! $refundShipping) {
            throw new OrderException('Indica almeno una quantità da rendere.');
        }
        if (trim($reason) === '') {
            throw new OrderException('La motivazione del reso è obbligatoria.');
        }
        $order->load('items', 'invoice');
        foreach ($qtyByItem as $itemId => $qty) {
            $it = $order->items->firstWhere('id', $itemId) ?? throw new OrderException('Riga non appartenente all\'ordine.');
            if (! Num::of($it->unit_net)->isPositive()) {
                throw new OrderException("«{$it->name}» è un omaggio o un componente: si rende insieme alla riga principale.");
            }
            if ($qty > $it->qty - $it->qty_refunded) {
                throw new OrderException("«{$it->name}»: quantità resa superiore a quella acquistata.");
            }
        }

        return DB::transaction(function () use ($order, $qtyByItem, $reason, $restock, $refundShipping): CreditNote {
            $note = $this->invoices->issueCreditNote($order, $qtyByItem, $reason, $refundShipping);
            RefundService::adoptExternal($order, $note);
            if ($refundShipping) {
                $order->increment('shipping_refunded_net', (float) $note->shipping_net);
            }
            foreach ($qtyByItem as $itemId => $qty) {
                $it = $order->items->firstWhere('id', $itemId);
                $orig = PvMovement::where('order_item_id', $it->id)->where('type', 'sale')->where('status', 'confirmed')->first();
                if ($orig) {
                    $this->reverse($orig, $it, (int) $qty, $note);
                }
                $it->increment('qty_refunded', (int) $qty);
                if ($restock && $it->parent_item_id === null) {
                    Product::whereKey($it->product_id)->whereNotNull('stock_qty')->increment('stock_qty', (int) $qty);
                }
            }
            $order->refresh()->load('items');
            $all = $order->items->filter(fn ($i) => Num::of($i->unit_net)->isPositive())->every(fn ($i) => $i->qty_refunded >= $i->qty);
            $order->update(['status' => $all ? 'refunded' : 'partially_refunded']);
            OrderEvent::log($order, 'return', 'Reso registrato: nota di credito '.$note->number.' di € '.number_format((float) $note->total, 2, ',', '.').($restock ? ' · merce rimessa in giacenza' : '').($refundShipping ? ' · spese di spedizione incluse' : '').' · '.$reason);

            return $note;
        });
    }

    private function reverse(PvMovement $orig, $item, int $qty, CreditNote $note): void
    {
        $origPeriod = $orig->period;
        $now = CarbonImmutable::now();
        if ($origPeriod->isClosed()) {
            $period = $this->periods->forDate($now);
            $at = $now;
        } else {
            $period = $origPeriod;
            $at = PeriodKey::parse($period->code)->contains($now) ? $now : \DateTimeImmutable::createFromInterface($orig->event_at);
        }
        $m = PvLedger::record(
            $period, $orig->consultant, $orig->kind, '-'.Num::of($item->pv_unit_frozen)->multipliedBy($qty), $at,
            cv: '-'.Num::of($item->cv_unit_frozen)->multipliedBy($qty), type: 'refund', reverses: $orig,
            externalRef: "refund:{$note->number}:{$item->id}",
            amount: '-'.Num::money(OrderService::grossUnit($item)->multipliedBy($qty)),
            vapAmount: ($vap = $item->vapAmount($qty)) === null ? null : '-'.$vap,
        );
        $m->update(['order_id' => $orig->order_id, 'order_item_id' => $item->id, 'credit_note_id' => $note->id]);
    }
}
