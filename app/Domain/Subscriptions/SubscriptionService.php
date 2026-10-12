<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions;

use App\Domain\Audit\AuditLog;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Abbonamenti (RF-A01, RF-A03, RF-A05).
 *
 * Ogni mese un ordine normale pagato con carta o con il saldo KMoney del cliente: i punti, il Direct, il Team e il Matching maturano come per qualsiasi ordine.
 * Con l'impegno minimo configurato (default 12 mesi, un ordine al mese) la spedizione è gratuita.
 * Addebito fallito → nuovo tentativo dopo qualche giorno; esauriti i tentativi l'abbonamento va in pausa e il cliente può riprenderlo.
 * Disdetta prima dell'impegno: consentita e registrata (OPEN-40, regola commerciale da definire).
 */
final class SubscriptionService
{
    private const NOT_LINKED = 'Per pagare con KMoney collega prima il tuo conto KMoney dalla tua area.';

    public function __construct(private readonly OrderService $orders) {}

    /**
     * Sottoscrive e addebita subito il primo ordine.
     *
     * @param  array<int,int>  $lines  product_id => qty
     * @param  array<string,mixed>  $billing
     * @param  array<int,array<int,int>>  $forOthers  destinatari dell'ordine multiplo
     */
    public function subscribe(User $user, array $lines, string $country, array $billing, int $commitmentMonths = 0, array $forOthers = [], ?DateTimeImmutable $now = null, string $method = 'card'): Subscription
    {
        $now ??= CarbonImmutable::now();
        if (! in_array($method, [Subscription::METHOD_CARD, Subscription::METHOD_KMONEY], true)) {
            throw new OrderException('L\'abbonamento si paga con carta o con KMoney.');
        }
        if ($method === Subscription::METHOD_KMONEY && ! $this->orders->kmoneyEnabled()) {
            throw new OrderException('Il pagamento con KMoney non è disponibile al momento: scegli la carta.');
        }
        // Un abbonamento rimasto «in attesa» (pagina di Stripe abbandonata) non blocca una nuova sottoscrizione.
        foreach (Subscription::where('user_id', $user->id)->where('status', Subscription::PENDING)->get() as $stale) {
            $this->cancel($stale, 'Pagamento iniziale non completato');
        }
        if ($commitmentMonths < 0 || $commitmentMonths > 60) {
            throw new OrderException('Durata dell\'impegno non valida.');
        }
        if (Subscription::where('user_id', $user->id)->whereIn('status', [Subscription::ACTIVE, Subscription::PAUSED])->exists()) {
            throw new OrderException('Hai già un abbonamento attivo o in pausa: gestiscilo dalla tua area.');
        }
        $free = (bool) config('lamess.free_shipping_repeat.enabled') && $commitmentMonths >= (int) config('lamess.free_shipping_repeat.min_months');
        $sub = Subscription::create([
            'user_id' => $user->id, 'status' => Subscription::ACTIVE, 'lines' => $lines, 'for_others' => $forOthers ?: null, 'country_code' => $country,
            'billing' => $billing, 'payment_method' => $method, 'commitment_months' => $commitmentMonths, 'free_shipping' => $free, 'started_at' => $now, 'next_run_at' => $now,
        ]);
        AuditLog::record('subscription.created', "Abbonamento creato per {$user->email}", $sub, ['impegno_mesi' => $commitmentMonths, 'spedizione_gratuita' => $free, 'metodo' => $method]);

        // Carta su una pagina esterna (Stripe): il primo pagamento lo fa il cliente lì e salva la carta per i cicli successivi.
        // L'abbonamento resta «in attesa» finché il webhook non conferma (vedi activateAfterCheckout).
        if ($method === Subscription::METHOD_CARD && $this->orders->usesRedirectCheckout()) {
            try {
                $this->orders->place($user, $lines, $country, 'card', $billing, $now, 'Primo ordine da abbonamento #'.$sub->id, $forOthers, $sub);
            } catch (Throwable $e) {
                $sub->delete();
                throw $e;
            }
            $sub->update(['status' => Subscription::PENDING, 'next_run_at' => null]);

            return $sub->refresh();
        }
        $this->runCycle($sub, $now);
        $sub->refresh();
        if ($method === Subscription::METHOD_KMONEY && $sub->cycles_done === 0 && $sub->last_error === self::NOT_LINKED) {
            $this->cancel($sub, 'Conto KMoney non collegato');
            throw new OrderException(self::NOT_LINKED);
        }

        return $sub;
    }

    /** Il primo ordine non è ancora pagato per intero (pagina di Stripe da completare): il webhook potrà attivare l'abbonamento. */
    public function awaitingFirstPayment(Subscription $sub): bool
    {
        return $sub->status === Subscription::PENDING || ($sub->status === Subscription::ACTIVE && $sub->cycles_done === 0);
    }

    /**
     * Il cliente ha pagato il primo ordine sulla pagina di Stripe e ha autorizzato i prossimi addebiti: salva la carta e parte il calendario.
     * Idempotente: se l'abbonamento non è più «in attesa» non fa nulla.
     */
    public function activateAfterCheckout(Subscription $sub, ?string $stripeCustomerId, ?string $stripePaymentMethodId, ?DateTimeImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();
        if (! $this->awaitingFirstPayment($sub)) {
            return $sub;
        }
        $sub->update([
            'status' => Subscription::ACTIVE, 'cycles_done' => 1, 'retry_count' => 0, 'last_error' => null,
            'stripe_customer_id' => $stripeCustomerId, 'stripe_payment_method_id' => $stripePaymentMethodId,
            'next_run_at' => CarbonImmutable::instance($sub->started_at)->addMonthsNoOverflow(1 + $sub->skip_months),
        ]);
        AuditLog::record('subscription.activated', "Abbonamento #{$sub->id} attivo: primo ordine pagato", $sub, ['carta_salvata' => $stripePaymentMethodId !== null]);

        return $sub->refresh();
    }

    /** Esegue gli abbonamenti in scadenza. @return array{paid:int, failed:int, paused:int} */
    public function runDue(?DateTimeImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $out = ['paid' => 0, 'failed' => 0, 'paused' => 0];
        foreach (Subscription::where('status', Subscription::ACTIVE)->where('next_run_at', '<=', $now)->orderBy('id')->get() as $sub) {
            $r = $this->runCycle($sub, $now);
            $out[$r]++;
        }

        return $out;
    }

    /** Un ciclo: ordine + addebito. @return 'paid'|'failed'|'paused' */
    public function runCycle(Subscription $sub, ?DateTimeImmutable $now = null): string
    {
        $now ??= CarbonImmutable::now();
        $lock = Cache::lock("subscription:{$sub->id}", 120);
        if (! $lock->get()) {
            return 'failed';
        }
        try {
            $sub->refresh();
            if ($sub->status !== Subscription::ACTIVE) {
                return 'failed';
            }
            $order = null;
            $kmoney = $sub->payment_method === Subscription::METHOD_KMONEY;
            try {
                // Un ordine rimasto a metà (quota KMoney già addebitata, resto in carta non riuscito) si riprende, non si rifà.
                $order = $sub->orders()->where('status', 'pending_payment')->latest('id')->first()
                    ?? $this->orders->place($sub->user, $sub->lines, $sub->country_code, $sub->payment_method, $sub->billing, $now, 'Ordine da abbonamento #'.$sub->id, $sub->for_others ?? [], $sub, [], $kmoney ? 'card' : null);
                if ($kmoney) {
                    $r = $this->orders->payByKMoney($order);
                    if (! $r->paid) {
                        throw new OrderException(match (true) {
                            $r->error === 'not_linked' => self::NOT_LINKED,
                            $r->redirectUrl !== null => 'KMoney chiede una conferma del cliente: un addebito automatico non è possibile.',
                            default => $r->error ?: 'Addebito KMoney non riuscito: saldo insufficiente o servizio non raggiungibile.',
                        });
                    }
                    $order->refresh();
                }
                // Carta, oppure il resto in carta dopo la quota KMoney.
                if ($order->status === 'pending_payment' && ! $this->orders->payByCard($order, [
                    'subscription_id' => $sub->id, 'stripe_customer' => $sub->stripe_customer_id, 'stripe_payment_method' => $sub->stripe_payment_method_id,
                ])) {
                    throw new OrderException($this->orders->lastCardError ?: 'Addebito sulla carta non riuscito.');
                }
            } catch (Throwable $e) {
                // Si annulla solo un ordine senza incassi: se la quota KMoney è già stata addebitata resta in attesa del resto.
                if ($order instanceof Order && $order->refresh()->status === 'pending_payment' && ! $order->payments()->where('status', 'paid')->exists()) {
                    $this->orders->cancel($order);
                }

                return $this->fail($sub, $e->getMessage(), $now);
            }
            $done = $sub->cycles_done + 1;
            $sub->update([
                'cycles_done' => $done, 'retry_count' => 0, 'last_error' => null,
                'next_run_at' => CarbonImmutable::instance($sub->started_at)->addMonthsNoOverflow($done + $sub->skip_months),
            ]);

            return 'paid';
        } finally {
            $lock->release();
        }
    }

    /**
     * Nuova carta per i prossimi addebiti. Un abbonamento attivo con un addebito appena fallito riprova al prossimo passaggio;
     * uno in pausa resta in pausa finché il cliente non lo riprende.
     */
    public function updateCard(Subscription $sub, ?string $stripeCustomerId, string $stripePaymentMethodId, ?DateTimeImmutable $now = null): Subscription
    {
        if ($sub->status === Subscription::CANCELLED) {
            throw new OrderException('L\'abbonamento è annullato.');
        }
        $now ??= CarbonImmutable::now();
        $data = ['stripe_payment_method_id' => $stripePaymentMethodId];
        if ($stripeCustomerId !== null) {
            $data['stripe_customer_id'] = $stripeCustomerId;
        }
        if ($sub->status === Subscription::ACTIVE && $sub->retry_count > 0) {
            $data += ['retry_count' => 0, 'last_error' => null, 'next_run_at' => $now];
        }
        $sub->update($data);
        AuditLog::record('subscription.card_updated', "Abbonamento #{$sub->id}: carta aggiornata", $sub);

        return $sub->refresh();
    }

    public function pause(Subscription $sub, string $reason = 'Sospeso dal cliente'): Subscription
    {
        if ($sub->status !== Subscription::ACTIVE) {
            throw new OrderException('Solo un abbonamento attivo si può mettere in pausa.');
        }
        $sub->update(['status' => Subscription::PAUSED, 'paused_at' => now(), 'next_run_at' => null]);
        AuditLog::record('subscription.paused', "Abbonamento #{$sub->id} in pausa", $sub, [], $reason);

        return $sub->refresh();
    }

    /** Riprende un abbonamento in pausa: il primo ordine parte subito, poi si riallinea al giorno di partenza. */
    public function resume(Subscription $sub, ?DateTimeImmutable $now = null): Subscription
    {
        if ($sub->status !== Subscription::PAUSED) {
            throw new OrderException('Solo un abbonamento in pausa si può riprendere.');
        }
        $now ??= CarbonImmutable::now();
        $sub->update(['status' => Subscription::ACTIVE, 'paused_at' => null, 'retry_count' => 0, 'last_error' => null, 'next_run_at' => $now]);
        AuditLog::record('subscription.resumed', "Abbonamento #{$sub->id} ripreso", $sub);
        $this->runCycle($sub, $now);

        return $sub->refresh();
    }

    /** Salta il prossimo addebito (es. vacanza): il calendario slitta di un mese, nessun ordine parte quel mese. */
    public function skipNext(Subscription $sub, ?DateTimeImmutable $now = null): Subscription
    {
        $now ??= CarbonImmutable::now();
        if ($sub->status !== Subscription::ACTIVE || $sub->next_run_at === null) {
            throw new OrderException('Si può saltare un mese solo su un abbonamento attivo.');
        }
        if ($sub->retry_count > 0) {
            throw new OrderException('L\'ultimo addebito non è riuscito: riprendi o metti in pausa l\'abbonamento.');
        }
        $next = $sub->next_run_at->addMonthsNoOverflow(1);
        $limit = CarbonImmutable::instance($now)->addMonthsNoOverflow((int) config('lamess.subscription.max_skip_ahead_months'));
        if ($next->greaterThan($limit)) {
            throw new OrderException('Puoi saltare al massimo fino a '.(int) config('lamess.subscription.max_skip_ahead_months').' mesi in anticipo: se ti serve di più, metti l\'abbonamento in pausa.');
        }
        $sub->update(['skip_months' => $sub->skip_months + 1, 'next_run_at' => $next]);
        AuditLog::record('subscription.skipped', "Abbonamento #{$sub->id}: prossimo addebito spostato al {$next->format('d/m/Y')}", $sub, ['mesi_saltati' => $sub->skip_months]);

        return $sub->refresh();
    }

    /** Cambia l'indirizzo di SPEDIZIONE dei prossimi ordini (non tocca quelli già fatti né la fatturazione). @param array<string,mixed> $shipping address, city, zip, province, label, recipient, phone */
    public function updateShipping(Subscription $sub, array $shipping): Subscription
    {
        if ($sub->status === Subscription::CANCELLED) {
            throw new OrderException('L\'abbonamento è annullato.');
        }
        foreach (['address', 'city', 'zip'] as $f) {
            if (blank($shipping[$f] ?? null)) {
                throw new OrderException('Indirizzo, città e CAP sono obbligatori.');
            }
        }
        $keys = ['label', 'recipient', 'address', 'city', 'zip', 'province', 'phone'];
        $before = $sub->billing['shipping'] ?? Arr::only($sub->billing, ['address', 'city', 'zip', 'province']);
        $after = Arr::only($shipping, $keys);
        $sub->update(['billing' => array_merge($sub->billing, ['shipping' => $after])]);
        AuditLog::record('subscription.address_changed', "Abbonamento #{$sub->id}: indirizzo di spedizione cambiato", $sub, ['prima' => $before, 'dopo' => $after]);

        return $sub->refresh();
    }

    public function cancel(Subscription $sub, string $reason = 'Disdetta dal cliente'): Subscription
    {
        if ($sub->status === Subscription::CANCELLED) {
            throw new OrderException('L\'abbonamento è già annullato.');
        }
        $this->releasePendingOrders($sub);
        $early = $sub->status !== Subscription::PENDING && $sub->commitment_months > 0 && $sub->cycles_done < $sub->commitment_months;
        $sub->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'early_cancel' => $early, 'next_run_at' => null]);
        AuditLog::record('subscription.cancelled', "Abbonamento #{$sub->id} annullato".($early ? ' prima dell\'impegno minimo' : ''), $sub, ['disdetta_anticipata' => $early, 'cicli' => $sub->cycles_done], $reason);

        return $sub->refresh();
    }

    /**
     * Chiude gli ordini rimasti in attesa: se la quota KMoney era già stata addebitata la riaccredita al cliente, altrimenti annulla l'ordine.
     * Se il rimborso non riesce l'ordine resta in sospeso e lo si vede nel registro.
     */
    private function releasePendingOrders(Subscription $sub): void
    {
        foreach ($sub->orders()->where('status', 'pending_payment')->get() as $o) {
            try {
                if ($this->orders->hasKMoneyToRefund($o)) {
                    $this->orders->refundKMoneyAndCancel($o);
                } elseif (! $o->payments()->where('status', 'paid')->exists()) {
                    $this->orders->cancel($o);
                }
            } catch (Throwable $e) {
                AuditLog::record('subscription.order_release_failed', "Abbonamento #{$sub->id}: ordine {$o->number} da chiudere a mano", $sub, ['errore' => $e->getMessage()]);
            }
        }
    }

    /** @return 'failed'|'paused' */
    private function fail(Subscription $sub, string $error, DateTimeImmutable $now): string
    {
        $tries = $sub->retry_count + 1;
        if ($tries >= (int) config('lamess.subscription.max_retries')) {
            $sub->update(['status' => Subscription::PAUSED, 'paused_at' => now(), 'retry_count' => $tries, 'last_error' => $error, 'next_run_at' => null]);
            $this->releasePendingOrders($sub);
            AuditLog::record('subscription.paused', "Abbonamento #{$sub->id} in pausa dopo {$tries} addebiti non riusciti", $sub, ['errore' => $error]);

            return 'paused';
        }
        $sub->update(['retry_count' => $tries, 'last_error' => $error, 'next_run_at' => CarbonImmutable::instance($now)->addDays((int) config('lamess.subscription.retry_days'))]);

        return 'failed';
    }
}
