<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Billing\InvoiceService;
use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;

/**
 * Carrello con prezzi, sconti, spedizione e totali. È l'unica fonte dei numeri: la usano il carrello, il checkout
 * e la creazione dell'ordine, così ciò che il cliente vede è esattamente ciò che paga.
 */
final class PricedCart
{
    /**
     * @param  array<string,PricedLine>  $lines
     * @param  list<AppliedDiscount>  $applied
     * @param  array<string,string>  $messages  codice (maiuscolo) => motivo per cui non è stato applicato
     * @param  array{threshold: string, missing: string, name: string}|null  $freeShippingHint
     */
    public function __construct(
        public readonly array $lines,
        public readonly array $applied,
        public readonly array $messages,
        public readonly BigDecimal $shippingListNet,
        public readonly BigDecimal $shippingNet,
        public readonly string $shippingVatRate,
        public readonly bool $shippingWaivedBySubscription,
        public readonly ?array $freeShippingHint,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function qty(): int
    {
        return array_sum(array_map(fn (PricedLine $l) => $l->qty, $this->lines));
    }

    /** @return list<array{net: string, rate: string}> */
    public function invoiceRows(): array
    {
        $rows = array_map(fn (PricedLine $l) => ['net' => (string) $l->lineNet(), 'rate' => (string) $l->product->vat_rate], array_values($this->lines));
        if ($this->shippingNet->isPositive()) {
            $rows[] = ['net' => (string) $this->shippingNet, 'rate' => $this->shippingVatRate];
        }

        return $rows;
    }

    /** @return array{net: string, vat: string, total: string, by_rate: array<string,mixed>} */
    public function totals(): array
    {
        return InvoiceService::totals($this->invoiceRows());
    }

    public function itemsDiscountNet(): BigDecimal
    {
        return array_reduce($this->lines, fn (BigDecimal $c, PricedLine $l) => $c->plus($l->discountNet()), Num::zero());
    }

    public function shippingDiscountNet(): BigDecimal
    {
        return $this->shippingListNet->minus($this->shippingNet);
    }

    /** Totale prodotti IVA inclusa prima degli sconti. */
    public function listGross(): BigDecimal
    {
        return array_reduce($this->lines, fn (BigDecimal $c, PricedLine $l) => $c->plus($l->listGrossLine()), Num::zero());
    }

    /** Totale prodotti IVA inclusa dopo gli sconti. */
    public function itemsGross(): BigDecimal
    {
        return array_reduce($this->lines, fn (BigDecimal $c, PricedLine $l) => $c->plus($l->grossLine()), Num::zero());
    }

    public function discountGross(): BigDecimal
    {
        return Num::money($this->listGross()->minus($this->itemsGross()));
    }

    /** Parte dei prodotti (IVA inclusa, dopo gli sconti) pagabile in KMoney, secondo la % impostata su ogni prodotto. */
    public function kmoneyItemsGross(): BigDecimal
    {
        return array_reduce($this->lines, fn (BigDecimal $c, PricedLine $l) => $c->plus($this->kmoneyLineGross($l)), Num::zero());
    }

    /** Parte di una riga (IVA inclusa, dopo gli sconti) che si paga in KMoney, secondo la % del prodotto. */
    public function kmoneyLineGross(PricedLine $l): BigDecimal
    {
        return Num::money($l->grossLine()->multipliedBy($l->product->kmoneyPercent())->dividedBy(100, 10, \Brick\Math\RoundingMode::HalfUp));
    }

    /** Quota pagabile in KMoney: i prodotti secondo la loro %, più la spedizione se tutti i prodotti sono pagabili al 100% in KMoney. */
    public function kmoneyGross(): BigDecimal
    {
        $share = $this->kmoneyItemsGross();
        if ($this->lines !== [] && $this->allKMoney()) {
            $share = $share->plus($this->shippingGross());
        }

        return $share;
    }

    /** Quota KMoney effettiva: mai oltre il totale dell'ordine. */
    public function kmoneyShare(): BigDecimal
    {
        $total = Num::of($this->totals()['total']);
        $share = $this->kmoneyGross();

        return $share->isGreaterThan($total) ? $total : $share;
    }

    /** Resto da pagare con carta o bonifico dopo la quota KMoney. */
    public function kmoneyRest(): BigDecimal
    {
        return Num::of($this->totals()['total'])->minus($this->kmoneyShare());
    }

    /** True se ogni prodotto del carrello si paga al 100% in KMoney. */
    public function allKMoney(): bool
    {
        foreach ($this->lines as $l) {
            if ($l->product->kmoneyPercent() < 100) {
                return false;
            }
        }

        return true;
    }

    /** True se almeno un prodotto del carrello accetta KMoney. */
    public function acceptsKMoney(): bool
    {
        foreach ($this->lines as $l) {
            if ($l->product->kmoneyPercent() > 0) {
                return true;
            }
        }

        return false;
    }

    public function shippingGross(): BigDecimal
    {
        return PricedLine::gross($this->shippingNet, $this->shippingVatRate);
    }

    public function shippingListGross(): BigDecimal
    {
        return PricedLine::gross($this->shippingListNet, $this->shippingVatRate);
    }

    /** @return list<array<string,mixed>> */
    public function appliedSnapshot(): array
    {
        return array_map(fn (AppliedDiscount $a) => $a->snapshot(), $this->applied);
    }
}
