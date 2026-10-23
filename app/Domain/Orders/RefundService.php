<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Engine\Support\Num;
use App\Models\CreditNote;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\Auth;

/**
 * Rimborso in denaro dopo un reso: la nota di credito dice quanto, qui si restituisce davvero.
 * Ogni importo torna sul metodo con cui era stato pagato (carta → carta, KMoney → KMoney); il bonifico si rimborsa a mano
 * e l'admin lo segna eseguito. La carta si rimborsa prima del KMoney quando l'ordine era misto.
 */
final class RefundService
{
    private const ORDER = ['card' => 0, 'transfer' => 1, 'kmoney' => 2];

    /** Inizio del motivo dei rimborsi registrati dal webhook perché eseguiti fuori dal portale (dashboard Stripe, KMoney…). */
    public const EXTERNAL = 'Rimborso eseguito fuori dal portale';

    /** Rimborsi già eseguiti fuori dal portale e non ancora collegati a una nota di credito: fattura e compensi non sono stati stornati. */
    public static function externalPending(Order $order): \Illuminate\Support\Collection
    {
        return Refund::where('order_id', $order->id)->whereNull('credit_note_id')->where('status', Refund::DONE)->where('reason', 'like', self::EXTERNAL.'%')->get();
    }

    /** Il reso con nota di credito regolarizza i rimborsi esterni dell'ordine: da quel momento sono coperti da un documento. */
    public static function adoptExternal(Order $order, CreditNote $note): int
    {
        return Refund::where('order_id', $order->id)->whereNull('credit_note_id')->where('status', Refund::DONE)->where('reason', 'like', self::EXTERNAL.'%')->update(['credit_note_id' => $note->id]);
    }

    public function __construct(private readonly PaymentProvider $cards, private readonly KMoneyProvider $kmoney) {}

    /** @return list<Refund> uno per metodo di pagamento coinvolto */
    public function payBack(Order $order, CreditNote $note, ?string $reason = null): array
    {
        $remaining = Num::of($note->total);
        $out = [];
        $paid = $order->payments()->where('status', 'paid')->get()->sortBy(fn (Payment $p) => self::ORDER[$p->method] ?? 9);
        foreach ($paid as $payment) {
            if (! $remaining->isPositive()) {
                break;
            }
            $already = Refund::where('payment_id', $payment->id)->where('status', '!=', Refund::FAILED)->sum('amount');
            $available = Num::of($payment->amount)->minus((string) $already);
            if (! $available->isPositive()) {
                continue;
            }
            $amount = $available->isLessThan($remaining) ? $available : $remaining;
            $refund = Refund::create([
                'order_id' => $order->id, 'credit_note_id' => $note->id, 'payment_id' => $payment->id, 'amount' => (string) Num::money($amount),
                'method' => $payment->method, 'status' => Refund::PENDING, 'reason' => $reason ?? $note->reason, 'created_by' => Auth::id(),
            ]);
            $this->execute($refund);
            $out[] = $refund->refresh();
            $remaining = $remaining->minus($amount);
        }

        return $out;
    }

    /** Riprova un rimborso automatico che non è andato a buon fine. */
    public function retry(Refund $refund): Refund
    {
        if ($refund->status !== Refund::FAILED) {
            throw new OrderException('Si riprovano solo i rimborsi non riusciti.');
        }
        $refund->update(['status' => Refund::PENDING, 'error' => null]);
        $this->execute($refund);

        return $refund->refresh();
    }

    /** Rimborso eseguito fuori dal portale (bonifico, dashboard della carta…): lo si segna come fatto. */
    public function markDone(Refund $refund, ?string $reference = null): Refund
    {
        if ($refund->status === Refund::DONE) {
            throw new OrderException('Questo rimborso è già stato eseguito.');
        }
        $refund->update(['status' => Refund::DONE, 'provider_ref' => filled($reference) ? $reference : $refund->provider_ref, 'error' => null, 'refunded_at' => now()]);
        OrderEvent::log($refund->order, 'refund_done', 'Rimborso di € '.self::eur($refund->amount).' ('.OrderService::methodLabel($refund->method).') segnato come eseguito'.(filled($reference) ? " · rif. {$reference}" : ''));

        return $refund->refresh();
    }

    private function execute(Refund $refund): void
    {
        $order = $refund->order;
        $payment = $refund->payment;
        $amount = (string) $refund->amount;
        $key = "lamess-refund-{$refund->id}";

        $result = match ($refund->method) {
            'card' => $this->cards instanceof RefundsCardPayments && $payment
                ? $this->cards->refundPayment($payment, $amount, $key)
                : null,
            'kmoney' => $this->kmoney instanceof PartialKMoneyRefund && $order->buyer
                ? $this->kmoney->refundAmount($order, $order->buyer, $amount, $key)
                : null,
            default => null,
        };

        $label = OrderService::methodLabel($refund->method);
        if ($result === null) {
            // Nessun rimborso automatico per questo metodo: resta da eseguire a mano.
            $refund->update(['status' => Refund::PENDING, 'error' => null]);
            OrderEvent::log($order, 'refund_pending', "Rimborso di € ".self::eur($amount)." da eseguire a mano ({$label})");

            return;
        }
        if ($result->paid) {
            $refund->update(['status' => Refund::DONE, 'provider_ref' => $result->reference, 'error' => null, 'refunded_at' => now()]);
            OrderEvent::log($order, 'refund_done', 'Rimborso di € '.self::eur($amount)." eseguito ({$label})".($result->reference ? " · rif. {$result->reference}" : ''));

            return;
        }
        $refund->update(['status' => Refund::FAILED, 'error' => $result->error]);
        OrderEvent::log($order, 'refund_failed', 'Rimborso di € '.self::eur($amount)." non riuscito ({$label}): ".($result->error ?: 'errore sconosciuto'));
    }

    private static function eur(string|int|float $v): string
    {
        return number_format((float) $v, 2, ',', '.');
    }
}
