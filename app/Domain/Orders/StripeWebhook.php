<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Subscriptions\SubscriptionService;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Payment;
use App\Models\Refund;
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
        if ($type === 'charge.refunded') {
            return $this->handleChargeRefunded($obj);
        }
        if (in_array($type, ['charge.dispute.created', 'charge.dispute.closed'], true)) {
            return $this->handleDispute($type, $obj);
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
            if ($order->status === 'cancelled') {
                Log::warning('Stripe: pagamento ricevuto per un ordine già annullato, da verificare e rimborsare', ['order' => $order->number]);
            }

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

    /**
     * Allinea un ordine con carta a ciò che dice Stripe, senza aspettare il webhook (ritorno dalla pagina di pagamento, controllo dell'admin).
     * Conferma solo se Stripe stesso risponde «pagato» per la sessione legata all'ordine.
     *
     * @return string esito come handle(), oppure 'no_session' / 'unavailable'
     */
    public function reconcile(Order $order, StripeCheckoutProvider $stripe): string
    {
        $ref = (string) $order->payments()->where('status', 'pending')->where('provider', 'stripe')->latest('id')->value('provider_ref');
        if (! str_starts_with($ref, 'cs_')) {
            return 'no_session';
        }
        $session = $stripe->retrieveCheckoutSession($ref);
        if ($session === null) {
            return 'unavailable';
        }
        if ((int) ($session['client_reference_id'] ?? 0) !== $order->id) {
            return 'unknown_order';
        }

        return $this->handle(['type' => 'checkout.session.completed', 'data' => ['object' => $session]]);
    }

    /**
     * Perché un ordine con carta è ancora in attesa, in parole semplici: legge lo stato reale della pagina di Stripe.
     * Non conferma né cambia nulla: serve solo all'admin per capire cosa è successo.
     */
    public function explainPending(Order $order, StripeCheckoutProvider $stripe): string
    {
        $ref = (string) $order->payments()->where('provider', 'stripe')->latest('id')->value('provider_ref');
        if (! str_starts_with($ref, 'cs_')) {
            return 'Il cliente non ha mai aperto la pagina di pagamento di Stripe: ha lasciato il checkout prima di arrivarci.';
        }
        $s = $stripe->retrieveCheckoutSession($ref, true);
        if ($s === null) {
            return 'Stripe non risponde: impossibile leggere lo stato del pagamento, riprova tra poco.';
        }
        $pi = is_array($s['payment_intent'] ?? null) ? $s['payment_intent'] : [];
        $err = is_array($pi['last_payment_error'] ?? null) ? $pi['last_payment_error'] : [];
        $code = (string) ($err['decline_code'] ?? $err['code'] ?? '');

        if ($err !== []) {
            $why = match ($code) {
                'insufficient_funds' => 'fondi insufficienti sulla carta',
                'expired_card' => 'carta scaduta',
                'incorrect_cvc', 'invalid_cvc' => 'codice di sicurezza (CVC) errato',
                'incorrect_number', 'invalid_number' => 'numero di carta errato',
                'lost_card', 'stolen_card', 'pickup_card' => 'carta bloccata dalla banca',
                'authentication_required', 'authentication_not_handled' => 'autenticazione 3D Secure richiesta dalla banca e non riuscita',
                'card_velocity_exceeded', 'withdrawal_count_limit_exceeded' => 'limite di operazioni o di spesa della carta superato',
                default => 'carta rifiutata dalla banca'.($code !== '' ? " ({$code})" : ''),
            };

            return 'Il cliente ha provato a pagare ma il pagamento è stato respinto: '.$why.'. Il cliente deve riprovare con un\'altra carta oppure contattare la sua banca.';
        }
        if (($pi['status'] ?? '') === 'requires_action') {
            return 'Il cliente ha inserito la carta ma non ha completato la conferma della banca (3D Secure, per esempio la notifica sull\'app o il codice SMS). Deve riaprire il pagamento e confermare.';
        }
        if (($s['status'] ?? '') === 'expired') {
            $when = isset($s['expires_at']) ? ' il '.\Illuminate\Support\Carbon::createFromTimestamp((int) $s['expires_at'], 'Europe/Rome')->format('d/m/Y H:i') : '';

            return 'La pagina di pagamento è scaduta'.$when.' senza che il cliente pagasse. L\'ordine non verrà mai pagato: annullalo e fai rifare l\'ordine al cliente.';
        }
        if (($s['status'] ?? '') === 'open') {
            return 'La pagina di pagamento è ancora aperta ma il cliente non ha concluso il pagamento e non risultano tentativi con la carta. Può ancora completarlo fino alla scadenza della pagina.';
        }
        if (($s['payment_status'] ?? '') === 'paid') {
            return 'Su Stripe il pagamento risulta incassato: usa di nuovo «Verifica su Stripe» per confermare l\'ordine (il webhook non è arrivato).';
        }

        return 'Stripe non segnala un pagamento riuscito e non indica un motivo preciso: controlla la sessione '.$ref.' nella dashboard di Stripe.';
    }

    /** Pagamento Stripe incassato dal portale a cui si riferisce un oggetto charge/dispute (per payment_intent). */
    private function paymentOf(string $paymentIntent): ?Payment
    {
        return str_starts_with($paymentIntent, 'pi_') ? Payment::where('provider_ref', 'like', $paymentIntent.'%')->where('method', 'card')->latest('id')->first() : null;
    }

    /**
     * Rimborso eseguito su Stripe (dashboard o API), anche parziale. Se il portale lo conosce già (reso fatto da qui) non fa nulla;
     * altrimenti lo registra come «eseguito fuori dal portale» e avvisa nella cronologia: fattura e compensi vanno stornati con un reso.
     *
     * @param  array<string,mixed>  $charge
     */
    private function handleChargeRefunded(array $charge): string
    {
        $payment = $this->paymentOf((string) ($charge['payment_intent'] ?? ''));
        if ($payment === null || $payment->order === null) {
            Log::warning('Stripe: rimborso su un pagamento sconosciuto al portale', ['charge' => $charge['id'] ?? null]);

            return 'unknown_payment';
        }
        $order = $payment->order;
        $total = (int) ($charge['amount_refunded'] ?? 0);
        $known = (int) round((float) Refund::where('payment_id', $payment->id)->where('status', '!=', Refund::FAILED)->sum('amount') * 100);
        $extra = $total - $known;
        if ($extra <= 0) {
            return 'already_known';
        }
        $amount = number_format($extra / 100, 2, '.', '');
        $last = $charge['refunds']['data'][0]['id'] ?? ($charge['id'] ?? null);
        Refund::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => $amount, 'method' => 'card', 'status' => Refund::DONE,
            'provider_ref' => is_string($last) ? $last : null, 'reason' => RefundService::EXTERNAL.' (Stripe)', 'refunded_at' => now(),
        ]);
        OrderEvent::log($order, 'refund_external', 'Rimborso di € '.number_format($extra / 100, 2, ',', '.').' eseguito direttamente su Stripe, fuori dal portale: fattura e compensi NON sono stati stornati. Usa «Regolarizza rimborso» (o «Reso e rimborso») per emettere la nota di credito.', true);
        Log::warning('Stripe: rimborso eseguito fuori dal portale', ['order' => $order->number, 'amount' => $amount]);

        return 'external_refund';
    }

    /**
     * Contestazione della banca (chargeback). All'apertura: nota nella cronologia con scadenza per rispondere e, se l'ordine è di
     * un abbonamento attivo, abbonamento in pausa (altri addebiti sulla stessa carta produrrebbero altre contestazioni).
     *
     * @param  array<string,mixed>  $dispute
     */
    private function handleDispute(string $type, array $dispute): string
    {
        $payment = $this->paymentOf((string) ($dispute['payment_intent'] ?? ''));
        if ($payment === null || $payment->order === null) {
            Log::warning('Stripe: contestazione su un pagamento sconosciuto al portale', ['dispute' => $dispute['id'] ?? null]);

            return 'unknown_payment';
        }
        $order = $payment->order;
        $id = (string) ($dispute['id'] ?? '');
        $eur = number_format(((int) ($dispute['amount'] ?? 0)) / 100, 2, ',', '.');
        if ($id !== '' && $order->events()->where('type', $type === 'charge.dispute.created' ? 'dispute' : 'dispute_closed')->where('message', 'like', '%'.$id.'%')->exists()) {
            return 'already_known';
        }
        if ($type === 'charge.dispute.closed') {
            $esito = match ((string) ($dispute['status'] ?? '')) {
                'won' => 'VINTA: i soldi restano all\'azienda',
                'lost' => 'PERSA: Stripe ha trattenuto € '.$eur.'; regolarizza con un reso se la merce non è dovuta',
                default => 'chiusa ('.($dispute['status'] ?? 'esito sconosciuto').')',
            };
            OrderEvent::log($order, 'dispute_closed', "Contestazione {$id} {$esito}", true);

            return 'dispute_closed';
        }
        $due = isset($dispute['evidence_details']['due_by']) ? ' · rispondere entro il '.\Illuminate\Support\Carbon::createFromTimestamp((int) $dispute['evidence_details']['due_by'], 'Europe/Rome')->format('d/m/Y H:i') : '';
        OrderEvent::log($order, 'dispute', "CONTESTAZIONE su Stripe ({$id}) di € {$eur} · motivo: ".($dispute['reason'] ?? 'non indicato').$due.' · carica le prove dalla dashboard di Stripe', true);
        Log::warning('Stripe: contestazione (chargeback)', ['order' => $order->number, 'dispute' => $id, 'amount' => $eur]);
        $sub = $order->subscription_id ? Subscription::find($order->subscription_id) : null;
        if ($sub !== null && $sub->status === Subscription::ACTIVE) {
            try {
                app(SubscriptionService::class)->pause($sub, 'Contestazione su Stripe');
                OrderEvent::log($order, 'dispute_pause', "Abbonamento #{$sub->id} messo in pausa per la contestazione: riprendilo solo dopo averla chiarita col cliente", true);
            } catch (\Throwable $e) {
                Log::warning('Stripe: abbonamento non messo in pausa dopo la contestazione', ['subscription' => $sub->id, 'error' => $e->getMessage()]);
            }
        }

        return 'dispute';
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
