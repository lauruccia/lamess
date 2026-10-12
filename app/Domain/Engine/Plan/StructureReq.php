<?php

declare(strict_types=1);

namespace App\Domain\Engine\Plan;

/** Requisito di struttura: «count» linee diverse con almeno il rank indicato. */
final readonly class StructureReq
{
    public function __construct(public string $rankCode, public int $count) {}
}
