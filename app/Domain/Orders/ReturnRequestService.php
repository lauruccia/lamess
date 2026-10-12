<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Engine\Support\Num;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\ReturnRequest;
use App\Models\User;

/** Richiesta di reso del cliente (entro N giorni dalla consegna) e decisione dell'admin. */
final class ReturnRequestService
{
    public static function windowDays(): int
    {
        return (int) config('lamess.return_window_days', 14);
    }

    /** Fino a quando il cliente può chiedere il reso (dalla consegna, o dalla spedizione/pagamento se manca). */
    public static function deadline(Order $order): ?\DateTimeInterface
    {
        $from = $order->delivered_at ?? $order->shipped_at ?? $order->paid_at;

        return $from?->addDays(self::windowDays());
    }

    public function canRequest(Order $order): bool
    {
        if (! in_array($order->status, ['shipped', 'delivered', 'partially_refunded'], true)) {
            return false;
        }
        if ($order->returnRequests()->where('status', ReturnRequest::REQUESTED)->exists()) {
            return false;
        }
        $deadline = self::deadline($order);

        return $deadline !== null && now()->lessThanOrEqualTo($deadline);
    }

    /** @param array<int,int|string> $qtyByItem order_item_id => quantità */
    public function request(Order $order, User $user, array $qtyByItem, string $reason): ReturnRequest
    {
        if (! $this->canRequest($order)) {
            throw new OrderException('Per questo ordine non puoi richiedere un reso (tempo scaduto o richiesta già aperta).');
        }
        if (trim($reason) === '') {
            throw new OrderException('Spiega il motivo del reso.');
        }
        $order->loadMissing('items');
        $clean = [];
        foreach ($qtyByItem as $itemId => $qty) {
            if ((int) $qty <= 0) {
                continue;
            }
            $it = $order->items->firstWhere('id', (int) $itemId) ?? throw new OrderException('Prodotto non appartenente all\'ordine.');
            if (! Num::of($it->unit_net)->isPositive()) {
                throw new OrderException("«{$it->name}» fa parte di un altro prodotto: si rende insieme a quello.");
            }
            if ((int) $qty > $it->qty - $it->qty_refunded) {
                throw new OrderException("«{$it->name}»: quantità superiore a quella acquistata.");
            }
            $clean[(int) $itemId] = (int) $qty;
        }
        if ($clean === []) {
            throw new OrderException('Indica almeno un prodotto da rendere.');
        }
        $req = ReturnRequest::create(['order_id' => $order->id, 'user_id' => $user->id, 'items' => $clean, 'reason' => trim($reason)]);
        OrderEvent::log($order, 'return_requested', 'Il cliente ha richiesto un reso: '.trim($reason));

        return $req;
    }

    public function reject(ReturnRequest $req, string $note): void
    {
        if ($req->status !== ReturnRequest::REQUESTED) {
            throw new OrderException('Richiesta già gestita.');
        }
        $req->update(['status' => ReturnRequest::REJECTED, 'admin_note' => $note ?: null, 'decided_at' => now()]);
        OrderEvent::log($req->order, 'return_rejected', 'Richiesta di reso rifiutata'.($note ? ': '.$note : '.'));
    }
}
