<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Plan\LegacyPlan;
use App\Domain\Billing\InvoiceService;
use App\Domain\Engine\Support\Num;
use App\Domain\Ledger\PvLedger;
use App\Models\BundleRule;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Period;
use App\Models\Product;
use App\Models\PvMovement;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\OrderPaid;
use App\Notifications\OrderShipped;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ordini: composizione (prezzi, omaggi, kit, spedizione), pagamento e conferma dei punti.
 * I PV nascono solo a pagamento confermato (RF-051) nel mese del pagamento (OPEN-25);
 * per i bonifici esiste prima un movimento «pending_payment» che blocca la chiusura del periodo.
 */
final class OrderService
{
    public function __construct(
        private readonly PeriodResolver $periods,
        private readonly InvoiceService $invoices,
        private readonly PaymentProvider $payments,
        private readonly KMoneyProvider $kmoney,
        private readonly CartPricer $pricer,
    ) {}

    /**
     * @param  array<int,int>  $lines  product_id => quantità
     * @param  array<string,mixed>  $billing  dati di fatturazione/spedizione
     * @param  array<int,array<int,int>>  $forOthers  ordine multiplo: user_id destinatario => [product_id => quantità]; una sola spedizione, fattura al pagante
     * @param  Subscription|null  $subscription  ordine generato da un abbonamento (spedizione gratuita se prevista)
     * @param  list<string>  $discountCodes  codici sconto inseriti dal cliente (gli sconti automatici si applicano da soli)
     * @param  string|null  $kmoneyRest  con pagamento KMoney: metodo per il resto (card|transfer) quando la % KMoney dei prodotti non copre tutto
     */
    public function place(User $buyer, array $lines, string $country, string $method, array $billing, ?DateTimeImmutable $now = null, ?string $notes = null, array $forOthers = [], ?Subscription $subscription = null, array $discountCodes = [], ?string $kmoneyRest = null): Order
    {
        $now ??= CarbonImmutable::now();
        if (! in_array($method, ['card', 'transfer', 'kmoney'], true)) {
            throw new OrderException('Metodo di pagamento non valido.');
        }
        if ($method === 'kmoney' && ! $this->kmoney->enabled()) {
            throw new OrderException('Il pagamento con KMoney non è attivo.');
        }
        if (! in_array($country, (array) config('lamess.shop_countries'), true)) {
            throw new OrderException('Il servizio non è ancora attivo nel paese selezionato.');
        }
        $lines = array_filter($lines, fn ($q) => (int) $q > 0);
        $forOthers = array_filter(array_map(fn ($l) => array_filter((array) $l, fn ($q) => (int) $q > 0), $forOthers), fn ($l) => $l !== []);
        if ($lines === [] && $forOthers === []) {
            throw new OrderException('Il carrello è vuoto.');
        }
        $snapshot = $this->billingSnapshot($buyer, $billing, $country);
        [$type, $beneficiary] = $this->resolveBuyer($buyer);

        // Assegnazioni: chi ordina (se ha righe) e, per l'ordine multiplo, ogni destinatario con le sue righe.
        $allocations = [];
        if ($lines !== []) {
            $allocations[] = ['user' => $buyer, 'lines' => $lines];
        }
        foreach ($forOthers as $uid => $l) {
            $recipient = User::find($uid) ?? throw new OrderException('Destinatario non trovato.');
            $this->assertCanOrderFor($buyer, $recipient);
            $allocations[] = ['user' => $recipient, 'lines' => $l];
        }

        $allIds = collect($allocations)->flatMap(fn ($a) => array_keys($a['lines']))->unique()->all();
        $products = Product::with('components.component')->whereIn('id', $allIds)->get()->keyBy('id');
        $rules = BundleRule::with('gift')->where('active', true)->get();
        $items = [];
        foreach ($allocations as $alloc) {
            [$rType, $rBeneficiary] = $this->resolveBuyer($alloc['user']);
            $meta = ['recipient' => $alloc['user'], 'beneficiary' => $rBeneficiary, 'kind' => $rType === 'consultant' ? 'pvc' : 'pvf'];
            foreach ($alloc['lines'] as $pid => $qty) {
                $p = $products[$pid] ?? throw new OrderException('Prodotto non trovato.');
                if (! Num::of($p->net_price)->isPositive()) {
                    throw new OrderException("«{$p->name}» non è acquistabile direttamente.");
                }
                if ($p->hasVariants()) {
                    throw new OrderException("«{$p->name}» ha delle varianti: scegline una.");
                }
                if (! $p->sellableIn($country)) {
                    throw new OrderException("«{$p->name}» non è disponibile.");
                }
                $items[] = ['product' => $p, 'qty' => (int) $qty, 'gift' => false, 'parent' => null] + $meta;
            }
            // Omaggi automatici (RF-042): in base alle quantità del singolo destinatario.
            foreach ($rules as $rule) {
                $n = $rule->giftsFor((int) ($alloc['lines'][$rule->trigger_product_id] ?? 0), $now);
                if ($n > 0 && $rule->gift->active) {
                    $items[] = ['product' => $rule->gift, 'qty' => $n, 'gift' => true, 'parent' => null] + $meta;
                }
            }
        }

        // Sconti e prezzi: stessa logica del carrello, ricalcolata qui sul server.
        $entries = [];
        foreach ($items as $i => $it) {
            if (! $it['gift']) {
                $entries[(string) $i] = ['product' => $it['product'], 'qty' => $it['qty']];
            }
        }
        $priced = $this->pricer->price($entries, $buyer, $discountCodes, $now, $country, $subscription !== null && $subscription->free_shipping);
        foreach (array_map(fn ($c) => strtoupper(trim((string) $c)), $discountCodes) as $code) {
            if (isset($priced->messages[$code]) && ! str_starts_with($priced->messages[$code], 'Non applicato:')) {
                throw new OrderException("Codice {$code}: ".$priced->messages[$code]);
            }
        }

        // Pagamento KMoney: la quota dipende dalla % impostata su ogni prodotto; con tutti i prodotti al 100% anche la spedizione è in KMoney; altrimenti il resto (spedizione inclusa) si paga con carta o bonifico.
        $kmoneyAmount = '0.00';
        if ($method === 'kmoney') {
            $total = Num::of($priced->totals()['total']);
            if (! $priced->kmoneyGross()->isPositive()) {
                throw new OrderException('Nessun prodotto del carrello può essere pagato con KMoney.');
            }
            $share = $priced->kmoneyShare();
            $kmoneyAmount = (string) Num::money($share);
            if (Num::of($kmoneyAmount)->isLessThan($total)) {
                // Ordine singolo: il resto si sceglie dopo l'addebito della quota KMoney (chooseKMoneyRest). Abbonamenti: il resto è sempre indicato (carta).
                if ($kmoneyRest !== null || $subscription !== null) {
                    if (! in_array($kmoneyRest, ['card', 'transfer'], true)) {
                        throw new OrderException('Scegli come pagare il resto: carta o bonifico.');
                    }
                }
            } else {
                $kmoneyRest = null;
            }
        } else {
            $kmoneyRest = null;
        }

        return DB::transaction(function () use ($buyer, $items, $country, $method, $snapshot, $now, $notes, $type, $beneficiary, $subscription, $priced, $kmoneyAmount, $kmoneyRest): Order {
            $this->reserveStock($items);
            $order = Order::create([
                'number' => 'tmp', 'buyer_user_id' => $buyer->id, 'buyer_type' => $type, 'beneficiary_consultant_id' => $beneficiary?->id,
                'country_code' => $country, 'payment_method' => $method, 'subtotal_net' => 0, 'vat_total' => 0, 'total' => 0,
                'billing' => $snapshot, 'notes' => $notes, 'subscription_id' => $subscription?->id,
            ]);
            $pvTotal = Num::zero();
            $cvTotal = Num::zero();
            foreach ($items as $idx => $it) {
                /** @var Product $p */
                $p = $it['product'];
                $gift = $it['gift'];
                $pl = $gift ? null : $priced->lines[(string) $idx];
                $pvUnit = $gift ? '0.00' : $this->pricer->discountedPoints($pl, $p->pvUnit(), $now);
                $cvUnit = $gift ? '0.00' : $this->pricer->discountedPoints($pl, $p->cvUnit(), $now);
                $parent = OrderItem::create([
                    'order_id' => $order->id, 'product_id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'qty' => $it['qty'],
                    'unit_net' => $gift ? '0.00' : (string) $pl->unitNet, 'list_unit_net' => $gift ? null : (string) $pl->listUnitNet, 'vat_rate' => $p->vat_rate,
                    'pv_unit_frozen' => $pvUnit, 'cv_unit_frozen' => $cvUnit, 'is_gift' => $gift,
                    'vap_mode' => $gift ? null : $p->vapSetting()[0] ?? null, 'vap_value' => $gift ? null : $p->vapSetting()[1] ?? null,
                    'validity_months' => $gift ? null : $p->customerValidityMonths(), 'client_count' => $gift ? null : $p->customerCount(),
                    'recipient_user_id' => $it['recipient']->id, 'beneficiary_consultant_id' => $it['beneficiary']?->id, 'pv_kind' => $it['kind'],
                ]);
                if (! $gift) {
                    $pvTotal = $pvTotal->plus(Num::of($pvUnit)->multipliedBy($it['qty']));
                    $cvTotal = $cvTotal->plus(Num::of($cvUnit)->multipliedBy($it['qty']));
                }
                // Kit (RF-043): componenti a prezzo e PV zero, collegati al padre.
                if ($p->is_kit && ! $gift) {
                    foreach ($p->components as $c) {
                        OrderItem::create([
                            'order_id' => $order->id, 'product_id' => $c->component_product_id, 'sku' => $c->component->sku, 'name' => $c->component->name,
                            'qty' => $c->qty * $it['qty'], 'unit_net' => '0.00', 'vat_rate' => $c->component->vat_rate,
                            'pv_unit_frozen' => '0.00', 'cv_unit_frozen' => '0.00', 'is_gift' => false, 'parent_item_id' => $parent->id,
                        ]);
                    }
                }
            }
            // Spedizione (RF-046, OPEN-18): non genera PV. Gratuita sugli ordini ripetitivi (RF-A03) o per sconto.
            $t = $priced->totals();
            $shipNet = (string) Num::money($priced->shippingNet);
            $order->update([
                'number' => sprintf('%s-%d-%05d', config('lamess.order_prefix'), (int) $now->format('Y'), $order->id),
                'subtotal_net' => Num::money(Num::of($t['net'])->minus($shipNet)), 'shipping_net' => $shipNet, 'shipping_vat_rate' => $priced->shippingVatRate,
                'shipping_waived' => $priced->shippingWaivedBySubscription, 'discount_net' => Num::money($priced->itemsDiscountNet()),
                'shipping_discount_net' => $priced->shippingWaivedBySubscription ? 0 : Num::money($priced->shippingDiscountNet()),
                'discounts' => $priced->applied === [] ? null : $priced->appliedSnapshot(),
                'vat_total' => $t['vat'], 'total' => $t['total'], 'pv_total' => $pvTotal, 'cv_total' => $cvTotal,
                'kmoney_amount' => $kmoneyAmount, 'kmoney_rest_method' => $kmoneyRest,
            ]);
            $this->recordRedemptions($order, $buyer, $priced);
            OrderEvent::log($order, 'created', 'Ordine creato · pagamento: '.self::methodLabel($method).($kmoneyRest ? ' + '.self::methodLabel($kmoneyRest) : ''));
            if ($method === 'kmoney') {
                Payment::create(['order_id' => $order->id, 'provider' => $this->kmoney->name(), 'method' => 'kmoney', 'amount' => $kmoneyAmount]);
                if ($kmoneyRest !== null) {
                    Payment::create(['order_id' => $order->id, 'provider' => $kmoneyRest === 'card' ? $this->payments->name() : 'bank_transfer', 'method' => $kmoneyRest, 'amount' => $order->restDue()]);
                }
            } else {
                Payment::create(['order_id' => $order->id, 'provider' => $method === 'card' ? $this->payments->name() : 'bank_transfer', 'method' => $method, 'amount' => $t['total']]);
            }

            // Bonifico: movimento in attesa nel periodo già aperto del mese corrente (se esiste).
            if ($method === 'transfer' || $kmoneyRest === 'transfer') {
                $period = $this->periods->forDate($now, open: false);
                if ($period) {
                    $this->createMovements($order->load('items'), $beneficiary, $period, $now, 'pending_payment');
                }
            }

            return $order->refresh()->load('items');
        });
    }

    /** Motivo del rifiuto dell'ultimo addebito con carta (serve a spiegarlo al cliente negli abbonamenti). */
    public ?string $lastCardError = null;

    /** Pagamento con carta: addebita e, se riuscito, conferma. @return bool pagato */
    public function payByCard(Order $order, array $details = []): bool
    {
        $r = $this->payments->charge($order, $details);
        $this->lastCardError = $r->paid ? null : $r->error;
        if (! $r->paid) {
            $order->payments()->where('status', 'pending')->update(['status' => 'failed']);
            $order->payments()->create(['provider' => $this->payments->name(), 'method' => 'card', 'amount' => $order->restDue(), 'status' => 'pending']);

            return false;
        }
        $this->markPaid($order, $r->reference);

        return true;
    }

    public function kmoneyEnabled(): bool
    {
        return $this->kmoney->enabled() && (bool) config('lamess.payments.kmoney', true);
    }

    /** @return array{card: bool, transfer: bool, kmoney: bool} metodi di pagamento offerti ora nel checkout */
    public function enabledMethods(): array
    {
        return ['card' => (bool) config('lamess.payments.card', true), 'transfer' => (bool) config('lamess.payments.transfer', true), 'kmoney' => $this->kmoneyEnabled()];
    }

    /**
     * Pagamento con KMoney: addebita la quota KMoney del cliente. Se l'ordine è tutto in KMoney lo conferma;
     * altrimenti resta in attesa del resto (carta/bonifico). Il risultato porta l'eventuale link di conferma su KMoney.
     */
    public function payByKMoney(Order $order): PaymentResult
    {
        if ($order->status !== 'pending_payment') {
            throw new OrderException("L'ordine {$order->number} non è in attesa di pagamento.");
        }
        $kp = $order->payments()->where('method', 'kmoney')->latest('id')->first();
        if ($kp && $kp->status === 'paid') {
            return new PaymentResult(true, $kp->provider_ref);
        }
        $r = $this->kmoney->charge($order, $order->buyer);
        if (! $r->paid) {
            if ($r->redirectUrl === null && $r->error !== 'not_linked') {
                $order->payments()->where('method', 'kmoney')->where('status', 'pending')->update(['status' => 'failed']);
                $order->payments()->create(['provider' => $this->kmoney->name(), 'method' => 'kmoney', 'amount' => $order->kmoney_amount, 'status' => 'pending']);
            }

            return $r;
        }

        return $this->settleKMoney($order, $r->reference);
    }

    /** Registra l'incasso della quota KMoney (anche da webhook) e, se non resta nulla da pagare, conferma l'ordine. */
    public function settleKMoney(Order $order, ?string $reference): PaymentResult
    {
        if (Num::of($order->restDue())->isPositive()) {
            $order->payments()->where('method', 'kmoney')->where('status', 'pending')->update(['status' => 'paid', 'provider_ref' => $reference, 'paid_at' => now()]);

            return new PaymentResult(true, $reference);
        }
        $this->markPaid($order, $reference);

        return new PaymentResult(true, $reference);
    }

    /** True se l'ordine ha una quota KMoney già incassata e non ancora rimborsata. */
    public function hasKMoneyToRefund(Order $order): bool
    {
        return $order->payment_method === 'kmoney' && $order->payments()->where('method', 'kmoney')->where('status', 'paid')->exists();
    }

    /**
     * Dopo l'addebito della quota KMoney: il cliente sceglie come pagare il resto, fra i metodi attivi (carta o bonifico).
     */
    public function chooseKMoneyRest(Order $order, string $method, ?DateTimeImmutable $now = null): Order
    {
        $now ??= CarbonImmutable::now();
        if (! in_array($method, ['card', 'transfer'], true) || ! ($this->enabledMethods()[$method] ?? false)) {
            throw new OrderException('Questo metodo di pagamento non è disponibile al momento: scegline un altro.');
        }

        $order = DB::transaction(function () use ($order, $method, $now): Order {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending_payment' || $order->payment_method !== 'kmoney' || $order->kmoney_rest_method !== null || ! Num::of($order->restDue())->isPositive()) {
                throw new OrderException("Per l'ordine {$order->number} non c'è un resto da scegliere.");
            }
            if (! $order->payments()->where('method', 'kmoney')->where('status', 'paid')->exists()) {
                throw new OrderException('Prima si paga la quota KMoney: poi potrai scegliere come pagare il resto.');
            }
            $order->update(['kmoney_rest_method' => $method]);
            Payment::create(['order_id' => $order->id, 'provider' => $method === 'card' ? $this->payments->name() : 'bank_transfer', 'method' => $method, 'amount' => $order->restDue()]);
            OrderEvent::log($order, 'rest_method', 'Resto da € '.$order->restDue().' da pagare con '.self::methodLabel($method));
            if ($method === 'transfer') {
                $period = $this->periods->forDate($now, open: false);
                if ($period) {
                    $this->createMovements($order->load('items'), $order->beneficiary, $period, $now, 'pending_payment');
                }
            }

            return $order;
        });

        return $order->refresh();
    }

    /** Il cliente non ha pagato la parte in euro: riaccredita la quota KMoney e annulla l'ordine. */
    public function refundKMoneyAndCancel(Order $order): Order
    {
        if ($order->status !== 'pending_payment') {
            throw new OrderException('Si rimborsa la quota KMoney solo su ordini in attesa di pagamento.');
        }
        $payment = $order->payments()->where('method', 'kmoney')->where('status', 'paid')->latest('id')->first();
        if (! $payment) {
            throw new OrderException('Nessuna quota KMoney incassata da rimborsare.');
        }
        $r = $this->kmoney->refund($order, $order->buyer);
        if (! $r->paid) {
            throw new OrderException($r->error ?: 'Rimborso KMoney non riuscito.');
        }
        $payment->update(['status' => 'refunded', 'provider_ref' => trim(($payment->provider_ref ?? '').' | rimborso '.$r->reference, ' |')]);

        return $this->cancel($order);
    }

    public function usesRedirectCheckout(): bool
    {
        return $this->payments instanceof RedirectPaymentProvider;
    }

    /** Apre la pagina di pagamento esterna e lega la sessione al pagamento in sospeso dell'ordine. */
    public function startCardCheckout(Order $order, string $successUrl, string $cancelUrl): CheckoutSession
    {
        if (! $this->payments instanceof RedirectPaymentProvider) {
            throw new OrderException('Il pagamento con carta non usa una pagina esterna.');
        }
        if ($order->status !== 'pending_payment') {
            throw new OrderException("L'ordine {$order->number} non è in attesa di pagamento.");
        }
        $session = $this->payments->createCheckout($order, $successUrl, $cancelUrl);
        $payment = $order->payments()->where('status', 'pending')->latest('id')->first()
            ?? $order->payments()->create(['provider' => $this->payments->name(), 'method' => 'card', 'amount' => $order->total]);
        $payment->update(['provider' => $this->payments->name(), 'provider_ref' => $session->id]);

        return $session;
    }

    /** Conferma del pagamento (carta riuscita o bonifico riconciliato dall'admin). */
    public function markPaid(Order $order, ?string $reference = null, ?DateTimeImmutable $at = null): Order
    {
        $at ??= CarbonImmutable::now();

        $order = DB::transaction(function () use ($order, $reference, $at): Order {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'pending_payment') {
                throw new OrderException("L'ordine {$order->number} non è in attesa di pagamento.");
            }
            $pending = $order->payments()->where('status', 'pending')->get();
            if ($pending->count() > 1 && $pending->contains('method', 'kmoney')) {
                throw new OrderException("Ordine {$order->number}: la quota KMoney non è ancora stata addebitata.");
            }
            $order->load('items');
            if (Num::of($order->pv_total)->isPositive()) {
                $period = $this->periods->forDate($at);
                $this->confirmMovements($order, $order->beneficiary, $period, $at);
            }
            $payment = $order->payments()->where('status', 'pending')->latest('id')->first()
                ?? $order->payments()->create(['provider' => 'manual', 'method' => $order->payment_method, 'amount' => $order->total]);
            $payment->update(['status' => 'paid', 'provider_ref' => $reference, 'paid_at' => $at, 'reconciled_at' => $at]);
            $order->update(['status' => 'paid', 'paid_at' => $at]);

            return $order;
        });

        $this->invoices->issueForOrder($order->load('items'));
        OrderEvent::log($order, 'paid', 'Pagamento confermato'.(filled($reference) ? " (rif. {$reference})" : '').(LegacyPlan::hidden() ? ': fattura generata' : ': fattura e punti generati'));
        Notification::send($order->buyer, new OrderPaid($order));

        return $order->refresh();
    }

    public function cancel(Order $order): Order
    {
        if ($order->status !== 'pending_payment') {
            throw new OrderException('Si possono annullare solo ordini in attesa di pagamento.');
        }
        if ($this->hasKMoneyToRefund($order)) {
            throw new OrderException('La quota KMoney è già stata incassata: usa «Rimborsa KMoney» per riaccreditarla e annullare l\'ordine.');
        }
        DB::transaction(function () use ($order): void {
            DiscountRedemption::where('order_id', $order->id)->delete();
            $this->restoreStock($order);
            PvMovement::where('order_id', $order->id)->where('status', 'pending_payment')->get()->each->update(['status' => 'cancelled']);
            $order->payments()->where('status', 'pending')->update(['status' => 'failed']);
            $order->update(['status' => 'cancelled']);
            OrderEvent::log($order, 'cancelled', 'Ordine annullato');
        });

        return $order->refresh();
    }

    /** Etichetta italiana del metodo di pagamento. */
    public static function methodLabel(?string $method): string
    {
        return match ($method) {
            'card' => 'carta', 'kmoney' => 'KMoney', 'transfer' => 'bonifico', default => (string) $method,
        };
    }

    /** L'ordine pagato passa «in preparazione» (magazzino). */
    public function startProcessing(Order $order): Order
    {
        if ($order->status !== 'paid') {
            throw new OrderException('Si prepara solo un ordine pagato e non ancora spedito.');
        }
        $order->update(['status' => 'processing', 'processing_at' => now()]);
        OrderEvent::log($order, 'processing', 'Ordine in preparazione');

        return $order->refresh();
    }

    public function ship(Order $order, ?string $tracking = null, ?string $carrier = null): Order
    {
        if (! in_array($order->status, ['paid', 'processing', 'shipped'], true)) {
            throw new OrderException('Si spedisce solo un ordine pagato.');
        }
        $first = $order->status !== 'shipped';
        $order->update(['status' => 'shipped', 'tracking_code' => $tracking, 'carrier' => $carrier, 'shipped_at' => $order->shipped_at ?? now()]);
        OrderEvent::log($order, 'shipped', ($first ? 'Ordine spedito' : 'Dati di spedizione aggiornati').($carrier ? " con {$carrier}" : '').(filled($tracking) ? " · tracking {$tracking}" : ''));
        if ($first) {
            Notification::send($order->buyer, new OrderShipped($order->refresh()));
        }

        return $order->refresh();
    }

    public function markDelivered(Order $order): Order
    {
        if ($order->status !== 'shipped') {
            throw new OrderException('Si segna come consegnato solo un ordine spedito.');
        }
        $order->update(['status' => 'delivered', 'delivered_at' => now()]);
        OrderEvent::log($order, 'delivered', 'Ordine consegnato');

        return $order->refresh();
    }

    /** Nota interna: la vede solo il personale, mai il cliente. */
    public function addNote(Order $order, string $note): OrderEvent
    {
        if (trim($note) === '') {
            throw new OrderException('Scrivi la nota.');
        }

        return OrderEvent::log($order, 'note', trim($note), internal: true);
    }

    /**
     * Giacenza: controlla che ci sia disponibilità per ciò che si vende e scala le quantità (omaggi inclusi, senza mai bloccare per un omaggio).
     *
     * @param  list<array<string,mixed>>  $items
     */
    private function reserveStock(array $items): void
    {
        $sold = [];
        $gifted = [];
        foreach ($items as $it) {
            $id = $it['product']->id;
            if ($it['gift']) {
                $gifted[$id] = ($gifted[$id] ?? 0) + $it['qty'];
            } else {
                $sold[$id] = ($sold[$id] ?? 0) + $it['qty'];
            }
        }
        $products = Product::whereIn('id', array_unique(array_merge(array_keys($sold), array_keys($gifted))))->lockForUpdate()->get()->keyBy('id');
        foreach ($sold as $id => $qty) {
            $available = $products[$id]->availableQty();
            if ($available !== null && $qty > $available) {
                throw new OrderException($available === 0 ? "«{$products[$id]->name}» è esaurito." : "«{$products[$id]->name}»: ne restano solo {$available}.");
            }
        }
        foreach ($products as $id => $p) {
            if (! $p->tracksStock()) {
                continue;
            }
            $left = (int) $p->stock_qty - ($sold[$id] ?? 0) - ($gifted[$id] ?? 0);
            Product::whereKey($id)->update(['stock_qty' => $p->allow_backorder ? $left : max(0, $left)]);
        }
    }

    /** Rimette in giacenza le quantità di un ordine annullato (i componenti dei kit non si scalano, quindi non si restituiscono). */
    private function restoreStock(Order $order): void
    {
        $byProduct = [];
        foreach ($order->items()->whereNull('parent_item_id')->get() as $it) {
            $byProduct[$it->product_id] = ($byProduct[$it->product_id] ?? 0) + $it->qty;
        }
        foreach ($byProduct as $id => $qty) {
            Product::whereKey($id)->whereNotNull('stock_qty')->increment('stock_qty', $qty);
        }
    }

    /** Registra gli utilizzi degli sconti, ricontrollando i limiti con la riga dello sconto bloccata (due ordini insieme non superano il tetto). */
    private function recordRedemptions(Order $order, User $buyer, PricedCart $priced): void
    {
        foreach ($priced->applied as $a) {
            $d = Discount::whereKey($a->discount->id)->lockForUpdate()->firstOrFail();
            if ($d->usage_limit !== null && DiscountRedemption::where('discount_id', $d->id)->count() >= $d->usage_limit) {
                throw new OrderException("Lo sconto «{$d->name}» è esaurito: ricontrolla il carrello.");
            }
            if ($d->usage_limit_per_user !== null && DiscountRedemption::where('discount_id', $d->id)->where('user_id', $buyer->id)->count() >= $d->usage_limit_per_user) {
                throw new OrderException("Hai già usato lo sconto «{$d->name}» il numero massimo di volte.");
            }
            DiscountRedemption::create(['discount_id' => $d->id, 'order_id' => $order->id, 'user_id' => $buyer->id, 'amount_net' => Num::money($a->totalNet())]);
        }
    }

    /** @return array{0: string, 1: Consultant|null} */
    private function resolveBuyer(User $buyer): array
    {
        $c = Consultant::where('user_id', $buyer->id)->where('status', 'active')->first();
        if ($c) {
            return ['consultant', $c];
        }
        $assigned = Customer::where('user_id', $buyer->id)->first()?->consultant;

        return ['customer', $assigned && $assigned->status !== 'terminated' ? $assigned : null];
    }

    /** @param array<string,mixed> $b */
    private function billingSnapshot(User $buyer, array $b, string $country): array
    {
        $type = ($b['type'] ?? 'private') === 'company' ? 'company' : 'private';
        foreach (['address', 'city', 'zip'] as $f) {
            if (blank($b[$f] ?? null)) {
                throw new OrderException('Completa l\'indirizzo di fatturazione e spedizione.');
            }
        }
        if ($type === 'private' && blank($b['tax_code'] ?? null)) {
            throw new OrderException('Il codice fiscale è obbligatorio per la fattura.');
        }
        if ($type === 'company') {
            if (blank($b['vat_number'] ?? null) || blank($b['company_name'] ?? null)) {
                throw new OrderException('Per le aziende servono ragione sociale e partita IVA.');
            }
            if ($country === 'IT' && blank($b['sdi_code'] ?? null) && blank($b['pec'] ?? null)) {
                throw new OrderException('Per le aziende italiane serve il codice SDI o la PEC.');
            }
        }

        return [
            'type' => $type,
            'name' => $type === 'company' ? $b['company_name'] : (trim(($b['first_name'] ?? '').' '.($b['last_name'] ?? '')) ?: $buyer->name),
            'first_name' => $type === 'company' ? null : ($b['first_name'] ?? null), 'last_name' => $type === 'company' ? null : ($b['last_name'] ?? null),
            'tax_code' => $b['tax_code'] ?? null, 'vat_number' => $b['vat_number'] ?? null, 'sdi_code' => $b['sdi_code'] ?? null, 'pec' => $b['pec'] ?? null,
            'address' => $b['address'], 'city' => $b['city'], 'zip' => $b['zip'], 'province' => $b['province'] ?? null,
            'country_code' => $country, 'email' => $buyer->email,
            // Dove consegnare: può essere diverso dalla fatturazione; se non indicato, vale l'indirizzo di fatturazione.
            'shipping' => $this->shippingSnapshot($b),
        ];
    }

    /** @param array<string,mixed> $b @return array<string,mixed> */
    private function shippingSnapshot(array $b): array
    {
        $s = is_array($b['shipping'] ?? null) ? $b['shipping'] : [];
        if (blank($s['address'] ?? null) || blank($s['city'] ?? null) || blank($s['zip'] ?? null)) {
            return ['label' => null, 'recipient' => null, 'address' => $b['address'], 'city' => $b['city'], 'zip' => $b['zip'], 'province' => $b['province'] ?? null, 'phone' => null];
        }

        return [
            'label' => $s['label'] ?? null, 'recipient' => $s['recipient'] ?? null, 'address' => $s['address'], 'city' => $s['city'], 'zip' => $s['zip'],
            'province' => $s['province'] ?? null, 'phone' => $s['phone'] ?? null,
        ];
    }

    /** Prezzo unitario IVA inclusa della riga, già al netto degli sconti di prezzo (è l'importo che conta per il cliente). */
    public static function grossUnit(OrderItem $it): BigDecimal
    {
        return Num::of((string) $it->unit_net)->multipliedBy(Num::of('100')->plus(Num::of((string) $it->vat_rate))->dividedBy(100, 10, RoundingMode::HalfUp));
    }

    private function externalRef(Order $order, OrderItem $it): string
    {
        return "order:{$order->number}:{$it->id}";
    }

    /** Un movimento per riga: PV e Team seguono il beneficiario della riga (ordine multiplo); in mancanza, quello dell'ordine. */
    private function createMovements(Order $order, ?Consultant $orderBeneficiary, Period $period, DateTimeImmutable $at, string $status): void
    {
        $orderKind = $order->buyer_type === 'consultant' ? 'pvc' : 'pvf';
        foreach ($order->items as $it) {
            if ($it->is_gift || $it->parent_item_id || ! Num::of($it->pv_unit_frozen)->isPositive()) {
                continue;
            }
            $hasOwn = $it->pv_kind !== null;
            $beneficiary = $hasOwn ? ($it->beneficiary_consultant_id ? Consultant::find($it->beneficiary_consultant_id) : null) : $orderBeneficiary;
            if ($beneficiary === null) {
                continue;
            }
            $m = PvLedger::record(
                $period, $beneficiary, $hasOwn ? $it->pv_kind : $orderKind, (string) Num::of($it->pv_unit_frozen)->multipliedBy($it->qty), $at,
                cv: (string) Num::of($it->cv_unit_frozen)->multipliedBy($it->qty), status: $status, externalRef: $this->externalRef($order, $it),
                amount: (string) Num::money(self::grossUnit($it)->multipliedBy($it->qty)),
                vapAmount: ($vap = $it->vapAmount((int) $it->qty)) === null ? null : (string) $vap, validityMonths: $it->validity_months === null ? null : (int) $it->validity_months, clientCount: $it->client_count === null ? null : (int) $it->client_count,
            );
            $m->update(['order_id' => $order->id, 'order_item_id' => $it->id]);
        }
    }

    /**
     * Chi può essere destinatario di un ordine multiplo dell’incaricato (RF-A02): i suoi clienti e gli incaricati diretti.
     *
     * @return array<int,string> user_id => etichetta
     */
    public function recipientsFor(User $buyer): array
    {
        $me = Consultant::where('user_id', $buyer->id)->where('status', 'active')->first();
        if ($me === null || ! config('lamess.order_for_others')) {
            return [];
        }
        $out = [];
        foreach (Customer::with('user')->where('assigned_consultant_id', $me->id)->get() as $c) {
            if ($c->user) {
                $out[$c->user_id] = $c->user->name.' (cliente)';
            }
        }
        foreach (Consultant::with('user')->where('sponsor_id', $me->id)->where('status', 'active')->whereNotNull('user_id')->get() as $c) {
            $out[$c->user_id] = $c->name." [{$c->code}] (incaricato)";
        }

        return $out;
    }

    /** Ordine per conto di altri (RF-A02): clienti assegnati all’incaricato e suoi incaricati diretti. */
    private function assertCanOrderFor(User $buyer, User $recipient): void
    {
        if (! config('lamess.order_for_others')) {
            throw new OrderException('L\'ordine per conto di altri non è attivo.');
        }
        $me = Consultant::where('user_id', $buyer->id)->where('status', 'active')->first();
        $ok = false;
        if ($me !== null) {
            $ok = Customer::where('user_id', $recipient->id)->where('assigned_consultant_id', $me->id)->exists()
                || Consultant::where('user_id', $recipient->id)->where('sponsor_id', $me->id)->where('status', 'active')->exists();
        }
        if (! $ok) {
            throw new OrderException('Puoi ordinare solo per i tuoi clienti o per gli incaricati che hai sponsorizzato direttamente.');
        }
    }

    /** Conferma i movimenti in attesa (spostandoli nel mese del pagamento) oppure li crea. */
    private function confirmMovements(Order $order, ?Consultant $beneficiary, Period $period, DateTimeImmutable $at): void
    {
        $pending = PvMovement::where('order_id', $order->id)->where('status', 'pending_payment')->get();
        if ($pending->isEmpty()) {
            $this->createMovements($order, $beneficiary, $period, $at, 'confirmed');

            return;
        }
        foreach ($pending as $m) {
            $m->update(['period_id' => $period->id, 'event_at' => $at, 'status' => 'confirmed']);
        }
    }
}
