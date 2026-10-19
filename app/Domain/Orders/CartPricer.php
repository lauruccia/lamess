<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Engine\Support\Num;
use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\Discount;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ShippingRate;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Prezzi del carrello: sconti con codice e automatici (percentuale, importo fisso, scaglioni di quantità,
 * spedizione gratuita), cumulabilità, limiti d'uso e PV delle righe scontate (politica D02).
 *
 * Regole in breve:
 *  - gli importi «fissi» e le soglie di spesa sono IVA inclusa, come li vede il cliente; l'IVA si riconteggia per aliquota;
 *  - si ordinano i candidati dal più vantaggioso; il primo si applica sempre; i successivi solo se sia loro sia quelli
 *    già applicati sono «cumulabili»; chi è stato escluso lo dice un messaggio;
 *  - lo sconto finisce nel prezzo unitario netto di riga (8 decimali), quindi fattura, resi e note di credito
 *    restituiscono esattamente ciò che è stato pagato.
 */
final class CartPricer
{
    /** @var array<int,bool> user_id => è incaricato attivo */
    private array $consultantCache = [];

    /**
     * @param  array<string,array{product: Product, qty: int}>  $entries  chiave riga => prodotto e quantità (omaggi esclusi)
     * @param  list<string>  $codes  codici inseriti dal cliente
     */
    public function price(array $entries, ?User $user, array $codes = [], ?DateTimeInterface $now = null, string $country = 'IT', bool $shippingWaived = false): PricedCart
    {
        $now ??= CarbonImmutable::now();
        $codes = array_values(array_unique(array_map(fn ($c) => strtoupper(preg_replace('/\s+/', '', (string) $c)), array_filter($codes, fn ($c) => filled($c)))));

        // Stato di lavoro: importo netto corrente per riga (8 decimali).
        $state = [];
        foreach ($entries as $key => $e) {
            $list = Num::of($e['product']->netPriceFor($user))->toScale(8, RoundingMode::HalfUp);   // listino, o prezzo founder per gli incaricati founder
            $state[$key] = ['product' => $e['product'], 'qty' => (int) $e['qty'], 'list' => $list, 'cur' => $list->multipliedBy((int) $e['qty'])];
        }

        $weight = 0;
        foreach ($entries as $e) {
            $weight += (int) ($e['product']->weight_g ?? $e['product']->parent?->weight_g ?? 0) * (int) $e['qty'];
        }
        $ship = ShippingRate::forCountryAndWeight($country, $weight);
        $generalNet = $ship ? Num::money($ship->amount_net) : Num::zero();
        $shipRate = $ship ? (string) $ship->vat_rate : (string) config('lamess.default_vat_rate');
        // Spedizione per prodotto: chi ha un costo suo lo usa al posto della tariffa generale; il pacco costa quanto la voce più alta.
        $shipListNet = null;
        foreach ($entries as $e) {
            $own = $e['product']->shipping_net ?? $e['product']->parent?->shipping_net;
            $line = $own !== null ? Num::money($own) : $generalNet;
            $shipListNet = $shipListNet === null || $line->isGreaterThan($shipListNet) ? $line : $shipListNet;
        }
        $shipListNet ??= $generalNet;
        $shipNet = $shippingWaived ? Num::zero() : $shipListNet;

        [$candidates, $messages] = $this->candidates($state, $user, $codes, $now, $shipNet);

        // Dal più vantaggioso; a parità, prima il codice.
        usort($candidates, fn (array $a, array $b) => $b['saving']->compareTo($a['saving']) ?: ((int) $a['discount']->isAutomatic() <=> (int) $b['discount']->isAutomatic()));

        $applied = [];
        $allCombinable = true;
        foreach ($candidates as $c) {
            /** @var Discount $d */
            $d = $c['discount'];
            if ($applied !== [] && ! ($d->combinable && $allCombinable)) {
                if (filled($d->code)) {
                    $messages[$d->code] = 'Non applicato: non è cumulabile con lo sconto già in uso, che è più vantaggioso.';
                }

                continue;
            }
            // Dopo i primi sconti i requisiti si ricontrollano sugli importi correnti (es. soglia di spesa).
            [$ok, $reason] = $this->requirements($d, $state, $shipNet);
            if (! $ok) {
                if (filled($d->code)) {
                    $messages[$d->code] = $reason;
                }

                continue;
            }
            [$itemsNet, $shippingNet, $summary] = $this->apply($d, $state, $shipNet);
            if (! $itemsNet->isPositive() && ! $shippingNet->isPositive()) {
                continue;
            }
            $shipNet = $shipNet->minus($shippingNet);
            $applied[] = new AppliedDiscount($d, $itemsNet, $shippingNet, $summary);
            $allCombinable = $allCombinable && $d->combinable;
        }

        $lines = [];
        foreach ($state as $key => $s) {
            $unit = $s['cur']->dividedBy($s['qty'], 8, RoundingMode::HalfUp);
            $lines[(string) $key] = new PricedLine((string) $key, $s['product'], $s['qty'], $s['list'], $unit->isNegative() ? Num::zero() : $unit);
        }

        return new PricedCart(
            $lines, $applied, $messages, $shipListNet, $shipNet, $shipRate, $shippingWaived && $shipListNet->isPositive(),
            $this->freeShippingHint($state, $user, $now, $shipNet, $applied),
        );
    }

    /**
     * Politica D02 per un prodotto: i PV (e CV) di una riga scontata restano di catalogo o scalano con lo sconto.
     *
     * @return string proportional | catalog
     */
    public function pvDiscountMode(Product $product, ?DateTimeInterface $at = null): string
    {
        $mode = $product->pv_discount_mode ?? 'inherit';
        if ($mode === 'proportional') {
            return 'proportional';
        }
        if ($mode === 'fixed') {
            return 'catalog';
        }
        $plan = CompPlanVersion::inForce($at)?->plan();

        return $plan !== null ? (string) $plan->policies->get('discount_pv_policy') : 'catalog';
    }

    /** PV (o CV) unitari della riga dopo lo sconto, secondo la politica D02. */
    public function discountedPoints(PricedLine $line, string $catalogPoints, ?DateTimeInterface $at = null): string
    {
        if (! $line->isDiscounted() || $this->pvDiscountMode($line->product, $at) !== 'proportional') {
            return $catalogPoints;
        }

        return (string) Num::points(Num::of($catalogPoints)->multipliedBy($line->ratio()));
    }

    /**
     * @param  array<string,array<string,mixed>>  $state
     * @param  list<string>  $codes
     * @return array{0: list<array{discount: Discount, saving: BigDecimal}>, 1: array<string,string>}
     */
    private function candidates(array $state, ?User $user, array $codes, DateTimeInterface $now, BigDecimal $shipNet): array
    {
        $messages = [];
        $out = [];
        $byId = [];

        $consider = function (Discount $d, bool $typed) use ($state, $user, $now, $shipNet, &$messages, &$out, &$byId): void {
            [$ok, $reason] = $this->eligibility($d, $user, $now);
            if ($ok) {
                [$ok, $reason] = $this->requirements($d, $state, $shipNet);
            }
            if (! $ok) {
                if ($typed) {
                    $messages[(string) $d->code] = $reason;
                }

                return;
            }
            $scratch = $state;
            [$items, $shipping] = $this->apply($d, $scratch, $shipNet);
            $saving = $items->plus($shipping);
            if (! $saving->isPositive()) {
                if ($typed) {
                    $messages[(string) $d->code] = 'Questo codice non genera alcuno sconto sul carrello attuale.';
                }

                return;
            }
            if (! isset($byId[$d->id])) {
                $byId[$d->id] = true;
                $out[] = ['discount' => $d, 'saving' => $saving];
            }
        };

        if ($state !== []) {
            foreach (Discount::whereNull('code')->where('active', true)->get() as $d) {
                $consider($d, false);
            }
        }
        foreach ($codes as $code) {
            $d = Discount::where('code', $code)->first();
            if ($d === null) {
                $messages[$code] = 'Codice non valido.';

                continue;
            }
            if ($state === []) {
                $messages[$code] = 'Aggiungi dei prodotti al carrello per usare il codice.';

                continue;
            }
            $consider($d, true);
        }

        return [$out, $messages];
    }

    /** Condizioni indipendenti dal contenuto del carrello: attivo, date, destinatari, limiti d'uso. @return array{0: bool, 1: string} */
    private function eligibility(Discount $d, ?User $user, DateTimeInterface $now): array
    {
        if (! $d->active) {
            return [false, 'Codice non più attivo.'];
        }
        if ($d->starts_at !== null && CarbonImmutable::instance(\DateTimeImmutable::createFromInterface($now))->lt($d->starts_at)) {
            return [false, 'Il codice sarà valido dal '.$d->starts_at->timezone('Europe/Rome')->format('d/m/Y').'.'];
        }
        if (! $d->isWithinDates($now)) {
            return [false, 'Codice scaduto.'];
        }
        if ($d->usage_limit !== null && DiscountRedemption::where('discount_id', $d->id)->count() >= $d->usage_limit) {
            return [false, 'Codice esaurito.'];
        }
        if ($d->usage_limit_per_user !== null) {
            if ($user === null) {
                return [false, 'Accedi per usare questo codice.'];
            }
            if (DiscountRedemption::where('discount_id', $d->id)->where('user_id', $user->id)->count() >= $d->usage_limit_per_user) {
                return [false, 'Hai già usato questo codice il numero massimo di volte.'];
            }
        }
        switch ($d->audience) {
            case 'consultants':
                if ($user === null || ! $this->isConsultant($user)) {
                    return [false, 'Codice riservato agli incaricati.'];
                }
                break;
            case 'customers':
                if ($user !== null && $this->isConsultant($user)) {
                    return [false, 'Codice riservato ai clienti.'];
                }
                break;
            case 'first_order':
                if ($user === null) {
                    return [false, 'Accedi per usare questo codice: vale sul primo ordine.'];
                }
                if (Order::where('buyer_user_id', $user->id)->where('status', '!=', 'cancelled')->exists()) {
                    return [false, 'Codice valido solo sul primo ordine.'];
                }
                break;
        }

        return [true, ''];
    }

    private function isConsultant(User $user): bool
    {
        return $this->consultantCache[$user->id] ??= Consultant::where('user_id', $user->id)->where('status', 'active')->exists();
    }

    /**
     * Condizioni sul contenuto del carrello (prodotti interessati, quantità e spesa minime).
     *
     * @param  array<string,array<string,mixed>>  $state
     * @return array{0: bool, 1: string}
     */
    private function requirements(Discount $d, array $state, BigDecimal $shipNet): array
    {
        if ($d->type === Discount::FREE_SHIPPING && ! $shipNet->isPositive()) {
            return [false, 'La spedizione è già gratuita.'];
        }
        $keys = $this->eligibleKeys($d, $state);
        if ($keys === []) {
            return [false, 'Il codice non si applica ai prodotti nel carrello.'];
        }
        $qty = 0;
        $gross = Num::zero();
        foreach ($keys as $k) {
            $qty += $state[$k]['qty'];
            $gross = $gross->plus(PricedLine::gross($state[$k]['cur'], (string) $state[$k]['product']->vat_rate));
        }
        if ($d->min_qty !== null && $qty < $d->min_qty) {
            return [false, "Servono almeno {$d->min_qty} pezzi dei prodotti interessati."];
        }
        if ($d->min_subtotal !== null && $gross->isLessThan(Num::of($d->min_subtotal))) {
            return [false, 'Spesa minima di € '.number_format((float) $d->min_subtotal, 2, ',', '.').' sui prodotti interessati.'];
        }
        if ($d->type === Discount::TIERED && $this->tierPercent($d, $qty) === null) {
            $first = collect((array) $d->tiers)->pluck('min_qty')->map(fn ($q) => (int) $q)->filter()->min();

            return [false, 'Acquista almeno '.($first ?? 2).' pezzi per ottenere lo sconto.'];
        }

        return [true, ''];
    }

    /**
     * @param  array<string,array<string,mixed>>  $state
     * @return list<string>
     */
    private function eligibleKeys(Discount $d, array $state): array
    {
        $keys = [];
        // Lo sconto su una categoria vale anche per le sue sottocategorie.
        $categoryIds = $d->applies_to === 'categories' ? ProductCategory::withChildIds((array) $d->category_ids) : [];
        foreach ($state as $key => $s) {
            /** @var Product $p */
            $p = $s['product'];
            $in = match ($d->applies_to) {
                'products' => in_array($p->id, array_map('intval', (array) $d->product_ids), true) || ($p->parent_id !== null && in_array((int) $p->parent_id, array_map('intval', (array) $d->product_ids), true)),
                'categories' => $p->category_id !== null && in_array((int) $p->category_id, $categoryIds, true),
                default => true,
            };
            if ($in && $s['cur']->isPositive()) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    private function tierPercent(Discount $d, int $qty): ?BigDecimal
    {
        $best = null;
        foreach ((array) $d->tiers as $tier) {
            $min = (int) ($tier['min_qty'] ?? 0);
            if ($min > 0 && $qty >= $min && ($best === null || $min > $best['min'])) {
                $best = ['min' => $min, 'percent' => Num::of((string) ($tier['percent'] ?? 0))];
            }
        }

        return $best['percent'] ?? null;
    }

    /**
     * Applica lo sconto allo stato (modificandolo). Ritorna sconto sulle righe e sulla spedizione (netto) e la descrizione.
     *
     * @param  array<string,array<string,mixed>>  $state
     * @return array{0: BigDecimal, 1: BigDecimal, 2: string}
     */
    private function apply(Discount $d, array &$state, BigDecimal $shipNet): array
    {
        if ($d->type === Discount::FREE_SHIPPING) {
            return [Num::zero(), $shipNet, 'Spedizione gratuita'];
        }
        $keys = $this->eligibleKeys($d, $state);
        $qty = array_sum(array_map(fn ($k) => $state[$k]['qty'], $keys));
        $taken = Num::zero();

        if ($d->type === Discount::FIXED) {
            $grossByKey = [];
            $total = Num::zero();
            foreach ($keys as $k) {
                $grossByKey[$k] = $state[$k]['cur']->multipliedBy(Num::of('100')->plus(Num::of((string) $state[$k]['product']->vat_rate)))->dividedBy(100, 8, RoundingMode::HalfUp);
                $total = $total->plus($grossByKey[$k]);
            }
            $amount = BigDecimal::min(Num::of((string) $d->value), $total);
            $left = $amount;
            $last = array_key_last($keys);
            foreach ($keys as $i => $k) {
                $share = $i === $last ? $left : $amount->multipliedBy($grossByKey[$k])->dividedBy($total, 8, RoundingMode::HalfUp);
                $share = BigDecimal::min($share, $left);
                $left = $left->minus($share);
                $net = $share->multipliedBy(100)->dividedBy(Num::of('100')->plus(Num::of((string) $state[$k]['product']->vat_rate)), 8, RoundingMode::HalfUp);
                $net = BigDecimal::min($net, $state[$k]['cur']);
                $state[$k]['cur'] = $state[$k]['cur']->minus($net);
                $taken = $taken->plus($net);
            }

            return [$taken, Num::zero(), '− € '.number_format((float) $d->value, 2, ',', '.')];
        }

        $percent = $d->type === Discount::TIERED ? $this->tierPercent($d, $qty) : Num::of((string) $d->value);
        if ($percent === null || ! $percent->isPositive()) {
            return [Num::zero(), Num::zero(), ''];
        }
        $percent = BigDecimal::min($percent, Num::of('100'));
        foreach ($keys as $k) {
            $cut = $state[$k]['cur']->multipliedBy($percent)->dividedBy(100, 8, RoundingMode::HalfUp);
            $state[$k]['cur'] = $state[$k]['cur']->minus($cut);
            $taken = $taken->plus($cut);
        }

        return [$taken, Num::zero(), '− '.rtrim(rtrim((string) $percent->toScale(2), '0'), '.').'%'];
    }

    /**
     * «Ti mancano € X per la spedizione gratuita»: la soglia più bassa tra gli sconti automatici di spedizione non ancora scattati.
     *
     * @param  array<string,array<string,mixed>>  $state
     * @param  list<AppliedDiscount>  $applied
     * @return array{threshold: string, missing: string, name: string}|null
     */
    private function freeShippingHint(array $state, ?User $user, DateTimeInterface $now, BigDecimal $shipNet, array $applied): ?array
    {
        if ($state === [] || ! $shipNet->isPositive()) {
            return null;
        }
        $best = null;
        foreach (Discount::whereNull('code')->where('active', true)->where('type', Discount::FREE_SHIPPING)->whereNotNull('min_subtotal')->get() as $d) {
            [$ok] = $this->eligibility($d, $user, $now);
            if (! $ok || $d->min_qty !== null || $d->applies_to !== 'all') {
                continue;
            }
            $gross = Num::zero();
            foreach ($state as $s) {
                $gross = $gross->plus(PricedLine::gross($s['cur'], (string) $s['product']->vat_rate));
            }
            $missing = Num::of($d->min_subtotal)->minus($gross);
            if ($missing->isPositive() && ($best === null || Num::of($d->min_subtotal)->isLessThan(Num::of($best['threshold'])))) {
                $best = ['threshold' => (string) Num::money($d->min_subtotal), 'missing' => (string) Num::money($missing), 'name' => $d->name];
            }
        }

        return $best;
    }
}
