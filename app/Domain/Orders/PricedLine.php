<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Engine\Support\Num;
use App\Models\Product;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Riga del carrello dopo gli sconti: il prezzo unitario netto è quello che finisce nell'ordine e in fattura. */
final class PricedLine
{
    public function __construct(
        public readonly string $key,
        public readonly Product $product,
        public readonly int $qty,
        public readonly BigDecimal $listUnitNet,
        public readonly BigDecimal $unitNet,
    ) {}

    public function lineNet(): BigDecimal
    {
        return $this->unitNet->multipliedBy($this->qty);
    }

    public function listLineNet(): BigDecimal
    {
        return $this->listUnitNet->multipliedBy($this->qty);
    }

    public function discountNet(): BigDecimal
    {
        $d = $this->listLineNet()->minus($this->lineNet());

        return $d->isNegative() ? Num::zero() : $d;
    }

    public function isDiscounted(): bool
    {
        return $this->discountNet()->isPositive();
    }

    /** Rapporto prezzo scontato / listino (1 = nessuno sconto). */
    public function ratio(): BigDecimal
    {
        if (! $this->listUnitNet->isPositive()) {
            return BigDecimal::one();
        }

        return $this->unitNet->dividedBy($this->listUnitNet, 10, RoundingMode::HalfUp);
    }

    public function grossLine(): BigDecimal
    {
        return self::gross($this->lineNet(), (string) $this->product->vat_rate);
    }

    public function listGrossLine(): BigDecimal
    {
        return self::gross($this->listLineNet(), (string) $this->product->vat_rate);
    }

    public static function gross(BigDecimal $net, string $rate): BigDecimal
    {
        return Num::money($net->multipliedBy(Num::of('100')->plus(Num::of($rate)))->dividedBy(100, 10, RoundingMode::HalfUp));
    }
}
