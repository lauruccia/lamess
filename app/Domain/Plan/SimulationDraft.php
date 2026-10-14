<?php

declare(strict_types=1);

namespace App\Domain\Plan;

use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\Node;
use App\Domain\Engine\Model\PeriodInput;
use DateTimeImmutable;

/**
 * Copia di lavoro in memoria dello snapshot reale su cui si applicano i «cosa succede se» della simulazione.
 * Non tocca il database: parte da un PeriodInput già costruito e ne produce uno nuovo.
 */
final class SimulationDraft
{
    /** @var array<string, Node> */
    public array $nodes = [];

    /** @var list<Movement> */
    public array $movements = [];

    /** @var array<string, array{rank:string,top:string,sponsor:string}> agenti virtuali: qualifica richiesta, agente virtuale in cima al ramo, sponsor */
    public array $virtual = [];

    /** @var array<string, true> incaricati reali oggetto di una modifica */
    public array $targets = [];

    /** @var array<string, string> incaricato spostato => sponsor originario (o '') */
    public array $moved = [];

    public int $clientsAdded = 0;

    public int $ordersAdded = 0;

    /** @var list<string> */
    public array $warnings = [];

    private int $seq = 0;

    public readonly DateTimeImmutable $at;

    public readonly DateTimeImmutable $joinedAt;

    public function __construct(public readonly PeriodInput $base, bool $newAgentsInMonth)
    {
        foreach ($base->nodes as $n) {
            $this->nodes[$n->id] = $n;
        }
        $this->movements = $base->movements;
        $start = $base->period->start();
        // i movimenti simulati cadono il 2 del mese, alle 10: dentro il periodo e dentro i 30 giorni del Bonus di Struttura
        $this->at = $start->modify('+1 day +10 hours');
        $this->joinedAt = $newAgentsInMonth ? $start : $start->modify('-1 year');
    }

    public function nextId(string $kind): string
    {
        return 'sim-'.$kind.'-'.(++$this->seq);
    }

    public function isVirtual(string $id): bool
    {
        return isset($this->virtual[$id]);
    }

    public function toInput(): PeriodInput
    {
        return new PeriodInput(
            $this->base->period, $this->base->plan, array_values($this->nodes), $this->movements,
            $this->base->prior, $this->base->customerFirstPurchase, $this->base->customerCoverage,
        );
    }
}
