<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Support\PeriodKey;

/** Snapshot completo e immutabile di un calcolo. Nessun accesso al database durante il calcolo. */
final readonly class PeriodInput
{
    /**
     * @param  list<Node>  $nodes
     * @param  list<Movement>  $movements
     */
    public function __construct(
        public PeriodKey $period,
        public PlanConfig $plan,
        public array $nodes,
        public array $movements,
        public PriorState $prior = new PriorState,
        /** @var array<string, array<string, \DateTimeImmutable>> incaricato => cliente => data del primo acquisto confermato (piano Incaricati) */
        public array $customerFirstPurchase = [],
        /** @var array<string, array<string, list<array{0:int,1:int,2:int}>>> incaricato => cliente => tratti [primo mese, ultimo mese, quanti clienti vale] (mesi = anno*12+mese-1) in cui il cliente è valido per i prodotti acquistati (piano Incaricati) */
        public array $customerCoverage = [],
        /** @var array<string, array<string, int>> incaricato => codice qualifica => quanti agenti virtuali regalati (piano Incaricati): contano solo per i requisiti della qualifica */
        public array $rankHelps = [],
    ) {}
}
