<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/** Esito di una verifica della catena di hash del log di audit. */
final class AuditCheck
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $checked,
        public readonly int $total,
        public readonly ?int $badId,
        public readonly ?string $problem,
        public readonly ?int $headId,
        public readonly ?string $headHash,
        public readonly bool $dbProtected,
    ) {}

    /** «ID:hash» da annotare fuori dal sistema per accorgersi di un'eventuale cancellazione in coda. */
    public function anchor(): ?string
    {
        return $this->headId === null ? null : $this->headId.':'.$this->headHash;
    }
}
