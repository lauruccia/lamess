<?php

declare(strict_types=1);

namespace App\Domain\Engine\Plan;

use Brick\Math\BigDecimal;

final readonly class RankDef
{
    /** @param list<StructureReq> $structure */
    public function __construct(
        public string $code,
        public string $name,
        public int $sequence,
        public BigDecimal $prRequired,
        public bool $fiftyPercentRule,
        public BigDecimal $directRate,   // frazione: 0.18 = 18%
        public BigDecimal $rankBonus,
        public array $structure = [],
        public string $source = '',
        public int $minPearlFirstLevel = 0, // incaricati diretti con almeno la qualifica Pearl (piano Incaricati)
        public int $minClients = 0,     // clienti personali minimi per la qualifica (piano Incaricati); 0 = nessun requisito
        public int $minColumns = 0,       // numero di linee (colonne) che devono avere almeno «minColumnClients» clienti
        public int $minColumnClients = 0, // clienti minimi in ciascuna colonna (es. «3 colonne da 90»)
        /** @var list<string> requisiti disattivati: clients, pearl, structure, columns */
        public array $disabled = [],
    ) {}

    public function requires(string $requirement): bool
    {
        return ! in_array($requirement, $this->disabled, true);
    }
}
