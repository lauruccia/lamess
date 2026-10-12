<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use Brick\Math\BigDecimal;

/** Riga di compenso: spiega sempre perché esiste (movimento, percorso, rank, aliquota, politiche). */
final class Line
{
    public const BONUSES = ['direct', 'team', 'matching', 'rank', 'rank_installment', 'fsb', 'clawback', 'indirect', 'indirect_ext', 'structure'];

    /**
     * @param  array<string, mixed>  $explanation
     * @param  list<string>  $decisions
     */
    public function __construct(
        public string $key,
        public string $bonus,
        public string $beneficiaryId,
        public BigDecimal $amount,
        public BigDecimal $base,
        public ?BigDecimal $rate = null,
        public ?int $level = null,
        public ?string $sourceMovementId = null,
        public ?string $sourcePartnerId = null,
        public string $status = 'accrued', // accrued | held | excluded
        public string $reason = '',
        public array $explanation = [],
        public array $decisions = [],
    ) {}
}
