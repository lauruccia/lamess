<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use Brick\Math\BigDecimal;

/** Riga di compenso di un periodo chiuso, usata per calcolare gli storni. */
final readonly class OriginalLine
{
    public string $beneficiaryId;

    public function __construct(public string $key, public string $bonus, int|string $beneficiaryId, public BigDecimal $amount, public ?BigDecimal $rate, public string $periodCode)
    {
        $this->beneficiaryId = (string) $beneficiaryId;
    }
}
