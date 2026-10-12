<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Engine\Support\Num;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Subscription;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Stripe Checkout via API REST (nessuna libreria aggiuntiva): il cliente paga sulla pagina di Stripe. */
final class StripeCheckoutProvider implements RedirectPaymentProvider, RefundsCardPayments
{
    public function __construct(private readonly string $secretKey) {}

    public function name(): string
    {
        return 'stripe';
    }

    public static function cents(string $amount): int
    {
        return (int) (string) Num::of($amount)->multipliedBy(100)->toScale(0, RoundingMode::HalfUp);
    }

    /**
     * Senza carta salvata il pagamento non si fa «a server»: serve il passaggio dalla pagina di Stripe.
     * Per gli abbonamenti, dopo il primo pagamento la carta è salvata (cliente + metodo) e i cicli successivi si addebitano
     * qui, fuori sessione (off_session), senza il cliente davanti.
     */
    public function charge(Order $order, array $details = []): PaymentResult
    {
        $customer = (string) ($details['stripe_customer'] ?? '');
        $method = (string) ($details['stripe_payment_method'] ?? '');
        if ($customer === '' || $method === '') {
            return new PaymentResult(false, null, 'Il pagamento con carta si completa sulla pagina sicura di Stripe.');
        }

        try {
            $r = Http::asForm()->withToken($this->secretKey)->timeout(20)
                ->withHeaders(['Idempotency-Key' => "order-{$order->id}-offsession"])
                ->post('https://api.stripe.com/v1/payment_intents', [
                    'amount' => self::cents($order->restDue()), 'currency' => 'eur',
                    'customer' => $customer, 'payment_method' => $method,
                    'off_session' => 'true', 'confirm' => 'true',
                    'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
                    'description' => "Ordine {$order->number}",
                    'metadata' => ['order_id' => (string) $order->id, 'subscription_id' => (string) ($details['subscription_id'] ?? '')],
                ]);
        } catch (Throwable) {
            return new PaymentResult(false, null, 'Il servizio di pagamento non risponde.');
        }
        if ($r->successful() && $r->json('status') === 'succeeded') {
            return new PaymentResult(true, (string) $r->json('id'));
        }
        // Carta rifiutata, fondi insufficienti o autenticazione (3D Secure) richiesta: in ogni caso il ciclo non è pagato.
        $why = (string) ($r->json('error.message') ?? ($r->json('status') === 'requires_action' ? 'La banca chiede una conferma del cliente.' : 'Addebito rifiutato.'));

        return new PaymentResult(false, null, $why);
    }

    /** Rimborso (anche parziale) di un pagamento incassato: Stripe lo restituisce sulla carta del cliente. */
    public function refundPayment(Payment $payment, string $amount, string $idempotencyKey): PaymentResult
    {
        $intent = (string) $payment->provider_ref;
        if (! str_starts_with($intent, 'pi_')) {
            return new PaymentResult(false, null, 'Pagamento Stripe non riconosciuto: rimborsa dalla dashboard di Stripe e segna il rimborso come eseguito.');
        }
        try {
            $r = Http::asForm()->withToken($this->secretKey)->timeout(20)->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post('https://api.stripe.com/v1/refunds', [
                    'payment_intent' => $intent, 'amount' => self::cents($amount), 'reason' => 'requested_by_customer',
                    'metadata' => ['order_id' => (string) $payment->order_id],
                ]);
        } catch (Throwable) {
            return new PaymentResult(false, null, 'Stripe non risponde: il rimborso non è stato eseguito, riprova tra poco.');
        }
        if ($r->successful() && in_array($r->json('status'), ['succeeded', 'pending'], true)) {
            return new PaymentResult(true, (string) $r->json('id'));
        }

        return new PaymentResult(false, null, (string) ($r->json('error.message') ?? 'Rimborso rifiutato da Stripe.'));
    }

    /** Metodo di pagamento usato in un pagamento di Stripe (serve per salvare la carta dopo il primo ordine dell'abbonamento). */
    public function paymentMethodOf(string $paymentIntentId): ?string
    {
        if ($paymentIntentId === '') {
            return null;
        }
        try {
            $r = Http::withToken($this->secretKey)->timeout(15)->get('https://api.stripe.com/v1/payment_intents/'.rawurlencode($paymentIntentId));
        } catch (Throwable) {
            return null;
        }

        return $r->successful() && is_string($r->json('payment_method')) ? $r->json('payment_method') : null;
    }

    /** Pagina sicura di Stripe per cambiare la carta di un abbonamento (nessun addebito): al ritorno il webhook salva la nuova carta. */
    public function createCardUpdate(Subscription $sub, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $body = [
            'mode' => 'setup', 'payment_method_types' => ['card'],
            'success_url' => $successUrl, 'cancel_url' => $cancelUrl,
            'metadata' => ['subscription_id' => (string) $sub->id, 'purpose' => 'card_update'],
            'setup_intent_data' => ['metadata' => ['subscription_id' => (string) $sub->id]],
        ];
        if (filled($sub->stripe_customer_id)) {
            $body['customer'] = $sub->stripe_customer_id;
        } elseif ($sub->user?->email) {
            $body['customer_email'] = $sub->user->email;
        }
        try {
            $r = Http::asForm()->withToken($this->secretKey)->timeout(15)->post('https://api.stripe.com/v1/checkout/sessions', $body);
        } catch (Throwable) {
            throw new OrderException('Il servizio di pagamento non risponde: riprova tra poco.');
        }
        if (! $r->successful() || ! $r->json('url')) {
            throw new OrderException('Il servizio di pagamento ha rifiutato la richiesta: riprova tra poco.');
        }

        return new CheckoutSession((string) $r->json('id'), (string) $r->json('url'));
    }

    /** Carta registrata da una sessione «setup» di Stripe. */
    public function paymentMethodOfSetup(string $setupIntentId): ?string
    {
        if ($setupIntentId === '') {
            return null;
        }
        try {
            $r = Http::withToken($this->secretKey)->timeout(15)->get('https://api.stripe.com/v1/setup_intents/'.rawurlencode($setupIntentId));
        } catch (Throwable) {
            return null;
        }

        return $r->successful() && is_string($r->json('payment_method')) ? $r->json('payment_method') : null;
    }

    public function createCheckout(Order $order, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $email = $order->buyer?->email;
        $body = [
            'mode' => 'payment',
            'client_reference_id' => (string) $order->id,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => ['currency' => 'eur', 'unit_amount' => self::cents($order->restDue()), 'product_data' => ['name' => "Ordine {$order->number}"]],
            ]],
            'metadata' => ['order_id' => (string) $order->id, 'order_number' => (string) $order->number],
            'payment_intent_data' => ['metadata' => ['order_id' => (string) $order->id]],
        ];
        if ($email) {
            $body['customer_email'] = $email;
        }
        // Primo ordine di un abbonamento: Stripe crea il cliente e conserva la carta per gli addebiti dei mesi successivi.
        if ($order->subscription_id) {
            $body['customer_creation'] = 'always';
            $body['payment_intent_data']['setup_future_usage'] = 'off_session';
            $body['metadata']['subscription_id'] = (string) $order->subscription_id;
            $body['payment_intent_data']['metadata']['subscription_id'] = (string) $order->subscription_id;
        }

        try {
            $r = Http::asForm()->withToken($this->secretKey)->timeout(15)
                ->withHeaders(['Idempotency-Key' => "order-{$order->id}-".now()->format('YmdHi')])
                ->post('https://api.stripe.com/v1/checkout/sessions', $body);
        } catch (Throwable) {
            throw new OrderException('Il servizio di pagamento non risponde: riprova tra poco.');
        }
        if (! $r->successful() || ! $r->json('url')) {
            throw new OrderException('Il servizio di pagamento ha rifiutato la richiesta: riprova o scegli il bonifico.');
        }

        return new CheckoutSession((string) $r->json('id'), (string) $r->json('url'));
    }
}
