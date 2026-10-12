<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Engine\Support\Num;
use App\Models\Discount;
use Brick\Math\BigDecimal;

/** Uno sconto effettivamente applicato al carrello, con quanto ha tolto alle righe e alla spedizione (netto IVA). */
final class AppliedDiscount
{
    public function __construct(
        public readonly Discount $discount,
        public readonly BigDecimal $itemsNet,
        public readonly BigDecimal $shippingNet,
        public readonly string $summary,
    ) {}

    public function totalNet(): BigDecimal
    {
        return $this->itemsNet->plus($this->shippingNet);
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'id' => $this->discount->id, 'name' => $this->discount->name, 'code' => $this->discount->code, 'type' => $this->discount->type,
            'items_net' => (string) Num::money($this->itemsNet), 'shipping_net' => (string) Num::money($this->shippingNet), 'summary' => $this->summary,
        ];
    }
}
