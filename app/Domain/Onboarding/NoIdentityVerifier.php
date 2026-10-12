<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\Consultant;

/** Nessun provider: i documenti si caricano e li controlla il Consorzio. */
final class NoIdentityVerifier implements IdentityVerifier
{
    public function name(): string
    {
        return 'manual';
    }

    public function available(): bool
    {
        return false;
    }

    public function start(Consultant $consultant, string $returnUrl): IdentityStart
    {
        throw new OnboardingException('La verifica online non è attiva: carica i documenti.');
    }
}
