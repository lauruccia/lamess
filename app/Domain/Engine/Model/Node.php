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
    ) {
        $this->id = (string) $id;
        $this->sponsorId = $sponsorId === null ? null : (string) $sponsorId;
    }

    public function with(array $changes): self
    {
        $v = array_replace([
            'id' => $this->id, 'sponsorId' => $this->sponsorId, 'since' => $this->since, 'status' => $this->status,
            'onboardingComplete' => $this->onboardingComplete, 'bonusHold' => $this->bonusHold,
            'rankCapCode' => $this->rankCapCode, 'name' => $this->name,
        ], $changes);

        return new self(...$v);
    }
}
