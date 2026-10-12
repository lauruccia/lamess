<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use Brick\Math\BigDecimal;

/** Stato proveniente dai periodi chiusi. */
final readonly class PriorState
{
    /**
     * @param  array<string, BigDecimal>  $personalPrOpening
     * @param  list<AchievedRank>  $achievedRanks
     * @param  list<string>  $rankAwards  chiavi "partner:RANK" già pagate
     * @param  array<string, BigDecimal>  $activityPointsPrev
     * @param  array<string, string>  $firstActivityPeriod
     * @param  array<string, list<OriginalLine>>  $originalLines  per id movimento originario
     * @param  array<string, Movement>  $originalMovements
     * @param  list<InstallmentDue>  $installmentsDue
     * @param  list<HeldRankAward>  $heldRankAwards
     * @param  list<Movement>  $fsbMovements  movimenti confermati dei periodi precedenti (finestra del Fast Start)
     * @param  list<string>  $fsbAwards  chiavi "partner:step" già riconosciute nei periodi chiusi
     */
    public function __construct(
        public array $personalPrOpening = [],
        public array $achievedRanks = [],
        public array $rankAwards = [],
        public array $activityPointsPrev = [],
        public array $firstActivityPeriod = [],
        public array $originalLines = [],
        public array $originalMovements = [],
        public array $installmentsDue = [],
        public array $heldRankAwards = [],
        public array $fsbMovements = [],
        public array $fsbAwards = [],
    ) {}

    public function with(array $changes): self
    {
        return new self(...array_replace(get_object_vars($this), $changes));
    }
}
