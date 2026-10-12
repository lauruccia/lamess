<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\Consultant;

/** Verifica d'identità online (documento + selfie). Driver reali: Stripe Identity; altri provider implementano la stessa porta. */
interface IdentityVerifier
{
    public function name(): string;

    /** Falso quando non c'è un provider configurato: resta la verifica manuale dei documenti. */
    public function available(): bool;

    /** @throws OnboardingException se il servizio non risponde */
    public function start(Consultant $consultant, string $returnUrl): IdentityStart;
}
