<?php

declare(strict_types=1);

namespace App\Domain\Subscriptions;

use App\Domain\Access\AccountSuspension;
use App\Domain\Audit\AuditLog;
use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\KMoneySubscriptionSync;
use App\Domain\Orders\OrderException;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PaymentProvider;
use App\Domain\Orders\StripeCheckoutProvider;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\SubscriptionCardExpiring;
use App\Notifications\SubscriptionChargeFailed;
use App\Notifications\SubscriptionChargeReminder;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Abbonamenti (RF-A01, RF-A03, RF-A05).
 *
 * Ogni mese un ordine normale pagato con carta o con il saldo KMoney del cliente: le provvigioni maturano come per qualsiasi ordine.
 * La spedizione è sempre gratuita. L'impegno di 12 mesi (un ordine al mese) non è strettamente vincolante: il cliente può disdire quando vuole.
 * Addebito fallito → nuovo tentativo dopo qualche giorno; esauriti i tentativi l'abbonamento va in pausa e il cliente può riprenderlo.
 * Disdetta prima dell'impegno: sempre consentita e registrata (OPEN-40, chiusa: nessun vincolo).
 */
final class SubscriptionService
{
    private const NOT_LINKED = 'Per pagare con KMoney collega prima il tuo conto KMoney dalla tua area.';

    private const BLOCKED = 'Hai bloccato questo abbonamento su KMoney: sbloccalo su KMoney e poi riprendilo da qui.';

    /** Stato «in attesa della conferma del cliente su KMoney»: l'ordine del mese resta in sospeso e lo chiude il webhook di KMoney. */
    public const CONFIRM = 'KMoney chiede la tua conferma per questo addebito: usa il pulsante nell\'email che ti abbiamo inviato.';

    public function __construct(private readonly OrderService $orders) {}

    /**
     * Sottoscrive e addebita subito il primo ordine.
     *
     * @param  array<int,int>  $lines  product_id => qty
     * @param  array<string,mixed>  $billing
     * @param  array<int,array<int,int>>  $forOthers  destinatari dell'ordine multiplo
     */
    public function subscribe(User $user, array $lines, string $country, array $billing, int $commitmentMonths = 0, array $forOthers = [], ?DateTimeImmutable $now = null, string $method = 'card', int $intervalMonths = 1): Subscription
    {
        $now ??= CarbonImmutable::now();
        if (! in_array($intervalMonths, [1, 2], true)) {
            throw new OrderException('Cadenza dell\'abbonamento non valida: scegli mensile o bimestrale.');
        }
        if (! in_array($intervalMonths, Subscription::offeredCadences(), true)) {
            throw new OrderException($intervalMonths === 2 ? 'L\'abbonamento bimestrale non è disponibile al momento.' : 'L\'abbonamento mensile non è disponibile al momento.');
        }
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
        // Con l'abbonamento la spedizione è sempre gratuita, senza impegno minimo.
        $free = true;
        $sub = Subscription::create([
            'user_id' => $user->id, 'status' => Subscription::ACTIVE, 'lines' => $lines, 'for_others' => $forOthers ?: null, 'country_code' => $country,
            'billing' => $billing, 'payment_method' => $method, 'interval_months' => $intervalMonths, 'commitment_months' => $commitmentMonths, 'free_shipping' => $free, 'started_at' => $now, 'next_run_at' => $now,
        ]);
        AuditLog::record('subscription.created', "Abbonamento creato per {$user->email}", $sub, ['impegno_mesi' => $commitmentMonths, 'ogni_mesi' => $intervalMonths, 'spedizione_gratuita' => $free, 'metodo' => $method]);

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
        // Primo addebito non riuscito (dati mancanti, saldo, carta…): l'abbonamento non parte e nessun «pagato» al cliente.
        // Unica eccezione: KMoney attende la conferma del cliente (email già inviata), che chiuderà il primo ordine.
        if ($sub->cycles_done === 0 && $sub->last_error !== null && $sub->last_error !== self::CONFIRM) {
            $error = (string) $sub->last_error;
            $this->cancel($sub, 'Primo addebito non riuscito: '.$error);
            throw new OrderException($error);
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
            'next_run_at' => CarbonImmutable::instance($sub->started_at)->addMonthsNoOverflow((1 + $sub->skip_months) * $sub->intervalMonths()),
        ]);
        AuditLog::record('subscription.activated', "Abbonamento #{$sub->id} attivo: primo ordine pagato", $sub, ['carta_salvata' => $stripePaymentMethodId !== null]);

        return $sub->refresh();
    }

    /** Esegue gli abbonamenti in scadenza. @return array{paid:int, failed:int, paused:int} */
    public function runDue(?DateTimeImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $out = ['paid' => 0, 'failed' => 0, 'paused' => 0];
        foreach (Subscription::where('status', Subscription::ACTIVE)->where('next_run_at', '<=', $now)
            ->whereNotIn('user_id', AccountSuspension::suspendedUserIds())   // account sospeso o bannato: nessun addebito
            ->orderBy('id')->get() as $sub) {
            $r = $this->runCycle($sub, $now);
            $out[$r]++;
        }

        return $out;
    }

    /**
     * Avvisa il cliente quando la carta salvata scade entro card_expiry_days o prima del prossimo addebito. Una sola email per carta;
     * serve Stripe (la scadenza si legge da lì, con una piccola cache). @return int email inviate
     */
    public function sendCardExpiryNotices(?DateTimeImmutable $now = null): int
    {
        $days = (int) config('lamess.subscription.card_expiry_days');
        $stripe = app(PaymentProvider::class);
        if ($days <= 0 || ! $stripe instanceof StripeCheckoutProvider) {
            return 0;
        }
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $sent = 0;
        $subs = Subscription::where('status', Subscription::ACTIVE)->where('payment_method', Subscription::METHOD_CARD)
            ->whereNotNull('stripe_payment_method_id')->whereNotIn('user_id', AccountSuspension::suspendedUserIds())->orderBy('id')->get();
        foreach ($subs as $sub) {
            $pm = (string) $sub->stripe_payment_method_id;
            $done = "subscription-card-expiry-notified:{$sub->id}:{$pm}";
            if (Cache::has($done)) {
                continue;
            }
            $exp = Cache::remember("stripe-card-expiry:{$pm}", 3 * 86400, fn () => $stripe->cardExpiry($pm));
            if (! is_array($exp)) {
                continue;
            }
            $end = CarbonImmutable::create($exp['year'], $exp['month'], 1, 0, 0, 0, 'Europe/Rome')->endOfMonth();
            if (! ($end->lessThanOrEqualTo($now->addDays($days)) || ($sub->next_run_at !== null && $end->lessThanOrEqualTo($sub->next_run_at->addDay())))) {
                continue;
            }
            try {
                $sub->user?->notify(new SubscriptionCardExpiring($sub, $exp['month'], $exp['year'], $end->lessThan($now)));
                Cache::put($done, 1, 25 * 86400);
                $sent++;
            } catch (Throwable $e) {
                AuditLog::record('subscription.notify_failed', "Abbonamento #{$sub->id}: avviso carta in scadenza non inviato", $sub, ['errore' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Promemoria prima dell'addebito: per ogni abbonamento attivo con scadenza entro reminder_days giorni, una sola email per scadenza.
     * Se l'email non parte (errore di posta) non si segna come inviata e si riprova al prossimo passaggio.
     *
     * @return int email inviate
     */
    public function sendReminders(?DateTimeImmutable $now = null): int
    {
        $days = (int) config('lamess.subscription.reminder_days');
        if ($days <= 0) {
            return 0;
        }
        $now = CarbonImmutable::instance($now ?? CarbonImmutable::now());
        $sent = 0;
        $due = Subscription::where('status', Subscription::ACTIVE)->where('retry_count', 0)
            ->whereNotIn('user_id', AccountSuspension::suspendedUserIds())
            ->where('next_run_at', '>', $now)->where('next_run_at', '<=', $now->addDays($days))->orderBy('id')->get();
        foreach ($due as $sub) {
            if ($sub->reminded_for !== null && $sub->reminded_for->equalTo($sub->next_run_at)) {
                continue;
            }
            try {
                $last = $sub->orders()->whereIn('status', ['paid', 'shipped', 'delivered', 'completed'])->latest('id')->first();
                $sub->user?->notify(new SubscriptionChargeReminder($sub, $last === null ? null : ($last->payment_method === 'kmoney' ? Money::ky($last->kmoney_amount).' KY'.((float) $last->restDue() > 0.004 ? ' + € '.Money::eur($last->restDue()) : '') : '€ '.Money::eur($last->total))));
                $sub->update(['reminded_for' => $sub->next_run_at]);
                $sent++;
            } catch (Throwable $e) {
                AuditLog::record('subscription.notify_failed', "Abbonamento #{$sub->id}: promemoria pre-addebito non inviato", $sub, ['errore' => $e->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Un ciclo: ordine + addebito. @return 'paid'|'failed'|'paused'
     *
     * @param  bool  $manual  rilancio dell'admin: se non riesce non consuma tentativi e non sposta le scadenze
     */
    public function runCycle(Subscription $sub, ?DateTimeImmutable $now = null, bool $manual = false): string
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
            // Il cliente ha confermato su KMoney e il webhook ha già chiuso l'ordine del mese: niente da addebitare, si fa solo avanzare il calendario.
            if ($sub->last_error !== null && $sub->orders()->whereIn('status', Order::PAID_STATES)->count() > $sub->cycles_done) {
                $this->completeCycle($sub, CarbonImmutable::instance($now), $sub->orders()->whereIn('status', Order::PAID_STATES)->count());

                return 'paid';
            }
            $order = null;
            $kmoney = $sub->payment_method === Subscription::METHOD_KMONEY;
            try {
                // Un ordine rimasto a metà (quota KMoney già addebitata, resto in carta non riuscito) si riprende, non si rifà.
                $order = $sub->orders()->where('status', 'pending_payment')->latest('id')->first()
                    ?? $this->orders->place($sub->user, $sub->lines, $sub->country_code, $sub->payment_method, $sub->billing, $now, 'Ordine da abbonamento #'.$sub->id, $sub->for_others ?? [], $sub, [], $kmoney ? 'card' : null);
                if ($kmoney) {
                    // KMoney deve conoscere l'abbonamento prima dell'addebito: è così che il ciclo si lega all'abbonamento e il cliente può bloccarlo.
                    $this->syncKMoney($sub);
                    $r = $this->orders->payByKMoney($order);
                    if (! $r->paid) {
                        throw new OrderException(match (true) {
                            $r->error === 'subscription_blocked' => self::BLOCKED,
                            $r->error === 'not_linked' => self::NOT_LINKED,
                            $r->redirectUrl !== null => throw new ConfirmationRequired(self::CONFIRM, $r->redirectUrl),
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
                // In attesa della conferma del cliente su KMoney l'ordine NON si annulla: la conferma arriva dal webhook di KMoney,
                // che chiude solo gli ordini ancora in sospeso (se fosse annullato il cliente pagherebbe senza che il portale lo sappia).
                $waiting = $e instanceof ConfirmationRequired;
                // Si annulla solo un ordine senza incassi: se la quota KMoney è già stata addebitata resta in attesa del resto.
                if (! $waiting && $order instanceof Order && $order->refresh()->status === 'pending_payment' && ! $order->payments()->where('status', 'paid')->exists()) {
                    OrderEvent::log($order, 'charge_failed', 'Addebito dell\'abbonamento non riuscito: '.$e->getMessage(), true);
                    $this->orders->cancel($order);
                }

                // Il cliente ha fermato l'abbonamento su KMoney: ritentare non serve, si mette subito in pausa.
                if ($e->getMessage() === self::BLOCKED) {
                    return $this->pauseBlocked($sub);
                }

                return $this->fail($sub, $e->getMessage(), $now, $waiting ? $e->url : null, $manual);
            }
            $this->completeCycle($sub, $now);

            return 'paid';
        } finally {
            $lock->release();
        }
    }

    /** Il mese è pagato: registra il ciclo e porta il calendario alla prossima scadenza futura. */
    private function completeCycle(Subscription $sub, DateTimeImmutable $now, ?int $paidCycles = null): void
    {
        $done = $paidCycles ?? $sub->cycles_done + 1;
        $skipped = $sub->skip_months;
        $anchor = CarbonImmutable::instance($sub->started_at);
        $iv = $sub->intervalMonths();
        $next = $anchor->addMonthsNoOverflow(($done + $skipped) * $iv);
        // Dopo una pausa lunga le scadenze passate non si recuperano: si salta alla prima scadenza futura.
        while ($next->lessThanOrEqualTo($now)) {
            $skipped++;
            $next = $anchor->addMonthsNoOverflow(($done + $skipped) * $iv);
        }
        $sub->update(['cycles_done' => $done, 'retry_count' => 0, 'last_error' => null, 'skip_months' => $skipped, 'next_run_at' => $next]);
        $this->syncKMoney($sub);
    }

    /**
     * Un ordine di un abbonamento è stato incassato fuori dal ciclo (webhook KMoney dopo la conferma del cliente):
     * se il mese era in attesa si chiude subito, senza aspettare il passaggio notturno.
     */
    public function afterOrderSettled(Order $order): void
    {
        $sub = $order->subscription_id ? Subscription::find($order->subscription_id) : null;
        if ($sub !== null && $sub->status === Subscription::ACTIVE && $sub->last_error !== null) {
            $this->runCycle($sub);
        }
    }

    /**
     * Rilancio dell'admin: riprova l'addebito KMoney e, se serve ancora la conferma, rimanda al cliente l'email col pulsante.
     * Non consuma tentativi e non sposta le scadenze. @return 'paid'|'failed'|'paused'
     */
    public function requestConfirmation(Subscription $sub, ?DateTimeImmutable $now = null): string
    {
        if ($sub->payment_method !== Subscription::METHOD_KMONEY || $sub->status !== Subscription::ACTIVE) {
            throw new OrderException('Si può rimandare la conferma solo per un abbonamento attivo pagato con KMoney.');
        }

        return $this->runCycle($sub, $now, true);
    }

    /** @return list<int> giorni di attesa dopo il 1°, 2°, 3°… addebito non riuscito (l'ultimo vale anche per i successivi) */
    private function retryDays(): array
    {
        $v = array_values(array_filter(array_map('intval', (array) config('lamess.subscription.retry_days')), fn (int $d) => $d > 0));

        return $v !== [] ? $v : [1];
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
        $this->syncKMoney($sub);

        return $sub->refresh();
    }

    /**
     * Riprende un abbonamento in pausa. Se il mese in corso è già pagato non addebita nulla: il prossimo ordine resta alla sua data.
     * Se invece la scadenza è passata, l'ordine parte subito e i mesi di pausa non vengono recuperati.
     */
    public function resume(Subscription $sub, ?DateTimeImmutable $now = null): Subscription
    {
        if ($sub->status !== Subscription::PAUSED) {
            throw new OrderException('Solo un abbonamento in pausa si può riprendere.');
        }
        $now ??= CarbonImmutable::now();
        $due = CarbonImmutable::instance($sub->started_at)->addMonthsNoOverflow(($sub->cycles_done + $sub->skip_months) * $sub->intervalMonths());
        $payNow = $due->lessThanOrEqualTo($now);
        $sub->update(['status' => Subscription::ACTIVE, 'paused_at' => null, 'retry_count' => 0, 'last_error' => null, 'next_run_at' => $payNow ? $now : $due]);
        AuditLog::record('subscription.resumed', "Abbonamento #{$sub->id} ripreso", $sub, ['addebito_subito' => $payNow]);
        if ($payNow) {
            $this->runCycle($sub, $now);
        } else {
            $this->syncKMoney($sub);
        }

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
        $next = $sub->next_run_at->addMonthsNoOverflow($sub->intervalMonths());
        // L'orizzonte è in cicli: 3 mesi per il mensile, 6 per il bimestrale.
        $limit = CarbonImmutable::instance($now)->addMonthsNoOverflow((int) config('lamess.subscription.max_skip_ahead_months') * $sub->intervalMonths());
        if ($next->greaterThan($limit)) {
            throw new OrderException('Puoi saltare al massimo fino a '.((int) config('lamess.subscription.max_skip_ahead_months') * $sub->intervalMonths()).' mesi in anticipo: se ti serve di più, metti l\'abbonamento in pausa.');
        }
        $sub->update(['skip_months' => $sub->skip_months + 1, 'next_run_at' => $next]);
        AuditLog::record('subscription.skipped', "Abbonamento #{$sub->id}: prossimo addebito spostato al {$next->format('d/m/Y')}", $sub, ['mesi_saltati' => $sub->skip_months]);
        $this->syncKMoney($sub);

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
        $early = $sub->status !== Subscription::PENDING && $sub->commitment_months > 0 && $sub->cycles_done < $sub->commitmentCycles();
        $sub->update(['status' => Subscription::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'early_cancel' => $early, 'next_run_at' => null]);
        AuditLog::record('subscription.cancelled', "Abbonamento #{$sub->id} annullato".($early ? ' prima dell\'impegno minimo' : ''), $sub, ['disdetta_anticipata' => $early, 'cicli' => $sub->cycles_done], $reason);
        $this->syncKMoney($sub);

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
    private function fail(Subscription $sub, string $error, DateTimeImmutable $now, ?string $confirmUrl = null, bool $manual = false): string
    {
        // Rilancio dell'admin: si registra l'esito e si rimanda l'email, ma tentativi e scadenze restano come sono.
        if ($manual) {
            $sub->update(['last_error' => $error]);
            $this->syncKMoney($sub);
            $this->notifyChargeFailed($sub, false, 0, $confirmUrl);

            return 'failed';
        }
        $tries = $sub->retry_count + 1;
        if ($tries >= (int) config('lamess.subscription.max_retries')) {
            $sub->update(['status' => Subscription::PAUSED, 'paused_at' => now(), 'retry_count' => $tries, 'last_error' => $error, 'next_run_at' => null]);
            $this->releasePendingOrders($sub);
            AuditLog::record('subscription.paused', "Abbonamento #{$sub->id} in pausa dopo {$tries} addebiti non riusciti", $sub, ['errore' => $error]);
            $this->syncKMoney($sub);
            $this->notifyChargeFailed($sub, true, 0, $confirmUrl);

            return 'paused';
        }
        // Tentativi distanziati (es. dopo 1, 3 e 5 giorni): ogni avviso è diverso, l'ultimo avvisa che segue la pausa.
        $days = $this->retryDays();
        $wait = $days[min($tries, count($days)) - 1];
        $sub->update(['retry_count' => $tries, 'last_error' => $error, 'next_run_at' => CarbonImmutable::instance($now)->addDays($wait)]);
        $this->syncKMoney($sub);
        $this->notifyChargeFailed($sub, false, $wait, $confirmUrl);

        return 'failed';
    }

    /** Email al cliente: un errore di posta non deve mai fermare il ciclo di addebiti. */
    private function notifyChargeFailed(Subscription $sub, bool $paused, int $retryDays = 0, ?string $confirmUrl = null): void
    {
        try {
            $sub->user?->notify(new SubscriptionChargeFailed($sub, $paused, $retryDays, $confirmUrl, (int) $sub->retry_count, (int) config('lamess.subscription.max_retries')));
        } catch (Throwable $e) {
            AuditLog::record('subscription.notify_failed', "Abbonamento #{$sub->id}: email di addebito non riuscito non inviata", $sub, ['errore' => $e->getMessage()]);
        }
    }

    /** Il cliente ha bloccato l'abbonamento su KMoney: pausa subito, senza consumare i tentativi, e l'ordine in attesa si chiude. */
    private function pauseBlocked(Subscription $sub): string
    {
        $sub->update(['status' => Subscription::PAUSED, 'paused_at' => now(), 'last_error' => self::BLOCKED, 'next_run_at' => null]);
        $this->releasePendingOrders($sub);
        AuditLog::record('subscription.paused', "Abbonamento #{$sub->id} in pausa: bloccato dal cliente su KMoney", $sub, [], 'Bloccato dal cliente su KMoney');
        $this->syncKMoney($sub);

        return 'paused';
    }

    /**
     * Manda a KMoney la copia dell'abbonamento, così il cliente lo vede nella sua area KMoney (con importi e date) e può bloccarlo.
     * Solo per gli abbonamenti pagati con KMoney e mai bloccante: un problema qui non deve fermare né un addebito né una disdetta.
     */
    private function syncKMoney(Subscription $sub): void
    {
        if ($sub->payment_method !== Subscription::METHOD_KMONEY) {
            return;
        }
        try {
            $provider = app(KMoneyProvider::class);
            if ($provider instanceof KMoneySubscriptionSync) {
                $provider->syncSubscription($sub->refresh());
            }
        } catch (Throwable $e) {
            Log::warning('KMoney: copia dell\'abbonamento non inviata', ['subscription' => $sub->id, 'error' => $e->getMessage()]);
        }
    }
}
