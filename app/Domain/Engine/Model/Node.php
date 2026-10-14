<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use DateTimeImmutable;

/** Incaricato nello snapshot di rete del periodo. */
final readonly class Node
{
    public string $id;

    public ?string $sponsorId;

    public function __construct(
        int|string $id,
        int|string|null $sponsorId,
        public DateTimeImmutable $since,        // incaricato dalla firma della Lettera IVD
        public string $status = 'active',       // active | suspended | terminated
        public bool $onboardingComplete = true,
        public bool $bonusHold = false,         // provvedimento: trattenere i pagamenti (Reg. 8.1.d)
        public ?string $rankCapCode = null,     // provvedimento: revoca/sospensione qualifica (Reg. 8.1.c)
        public string $name = '',
        public ?string $rankHoldUntil = null,   // piano Incaricati: la qualifica attuale non si riduce fino a questa data (Y-m-d), scelta dall'admin per il singolo incaricato
        public ?string $previousRank = null,    // piano Incaricati: qualifica del sistema precedente (importato da Odoo); solo chi ce l'ha gode della protezione generale del piano
    ) {
        $this->id = (string) $id;
        $this->sponsorId = $sponsorId === null ? null : (string) $sponsorId;
    }

    /**
     * La qualifica di questo incaricato è mantenuta nel periodo: l'ultimo giorno del mese cade entro la data scelta dall'admin.
     *
     * @param  DateTimeImmutable  $periodEnd  fine esclusiva del periodo (inizio del mese successivo)
     */
    public function rankHeldIn(DateTimeImmutable $periodEnd): bool
    {
        return $this->rankHoldUntil !== null && $periodEnd->modify('-1 day')->format('Y-m-d') <= $this->rankHoldUntil;
    }

    public function with(array $changes): self
    {
        $v = array_replace([
            'id' => $this->id, 'sponsorId' => $this->sponsorId, 'since' => $this->since, 'status' => $this->status,
            'onboardingComplete' => $this->onboardingComplete, 'bonusHold' => $this->bonusHold,
            'rankCapCode' => $this->rankCapCode, 'name' => $this->name, 'rankHoldUntil' => $this->rankHoldUntil, 'previousRank' => $this->previousRank,
        ], $changes);

        return new self(...$v);
    }
}
