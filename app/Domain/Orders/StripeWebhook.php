<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Order;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

/** Verifica della firma e gestione degli eventi Stripe relativi agli ordini. */
final class StripeWebhook
{
    public function __construct(private readonly OrderService $orders) {}

    /** Firma «Stripe-Signature»: t=timestamp,v1=hmac_sha256("t.payload"); tolleranza 5 minuti. */
    public static function verify(string $payload, ?string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if ($secret === '' || $header === null || $header === '') {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if ($t === null || $sigs === [] || abs(($now ?? time()) - $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string,mixed> $event @return string esito (per i log e i test) */
    public function handle(array $event): string
    {
        $type = (string) ($event['type'] ?? '');
        $obj = $event['data']['object'] ?? [];
        if ($type === 'checkout.session.completed' && ($obj['mode'] ?? '') === 'setup') {
            return $this->handleCardUpdate($obj);
        }
        if (! in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return 'ignored';
        }
        if (($obj['payment_status'] ?? '') !== 'paid') {
            return 'not_paid';
        }
        $order = Order::find((int) ($obj['client_reference_id'] ?? $obj['metadata']['order_id'] ?? 0));
        if ($order === null) {
            return 'unknown_order';
        }
        if ($order->status !== 'pending_payment') {
            return 'already_processed';
        }
        if ((int) ($obj['amount_total'] ?? -1) !== StripeCheckoutProvider::cents($order->restDue()) || strtolower((string) ($obj['currency'] ?? '')) !== 'eur') {
            Log::warning('Stripe: importo non coincidente, ordine NON confermato', ['order' => $order->number, 'amount' => $obj['amount_total'] ?? null]);

            return 'amount_mismatch';
        }
        $this->orders->markPaid($order, (string) ($obj['payment_intent'] ?? $obj['id'] ?? ''));
        $this->activateSubscription($order, $obj);

        return 'paid';
    }

    /** Il cliente ha cambiato la carta dell'abbonamento. @param array<string,mixed> $obj */
    private function handleCardUpdate(array $obj): string
    {
        $sub = Subscription::find((int) ($obj['metadata']['subscription_id'] ?? 0));
        if ($sub === null) {
            return 'unknown_subscription';
        }
        $provider = app(PaymentProvider::class);
        $method = $provider instanceof StripeCheckoutProvider ? $provider->paymentMethodOfSetup((string) ($obj['setup_intent'] ?? '')) : null;
        if ($method === null) {
            Log::warning('Stripe: nuova carta non recuperata', ['subscription' => $sub->id]);

            return 'no_method';
        }
        app(SubscriptionService::class)->updateCard($sub, isset($obj['customer']) ? (string) $obj['customer'] : null, $method);

        return 'card_updated';
    }

    /** Primo ordine di un abbonamento: salva cliente e carta di Stripe e fa partire il calendario. @param array<string,mixed> $obj */
    private function activateSubscription(Order $order, array $obj): void
    {
        $sub = $order->subscription_id ? Subscription::find($order->subscription_id) : null;
        if ($sub === null || ! app(SubscriptionService::class)->awaitingFirstPayment($sub)) {
            return;
        }
        $provider = app(PaymentProvider::class);
        $method = $provider instanceof StripeCheckoutProvider ? $provider->paymentMethodOf((string) ($obj['payment_intent'] ?? '')) : null;
        if ($method === null) {
            Log::warning('Stripe: carta dell\'abbonamento non recuperata, i prossimi addebiti non potranno partire', ['subscription' => $sub->id]);
        }
        app(SubscriptionService::class)->activateAfterCheckout($sub, isset($obj['customer']) ? (string) $obj['customer'] : null, $method);
    }
}
