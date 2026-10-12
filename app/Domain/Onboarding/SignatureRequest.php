<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

/** @param string|null $hint  solo per il driver fake: mostra l'OTP in sviluppo */
final readonly class SignatureRequest
{
    public function __construct(public string $reference, public ?string $hint = null) {}
}
