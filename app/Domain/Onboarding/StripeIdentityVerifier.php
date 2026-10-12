<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\Consultant;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Stripe Identity: documento d'identità con selfie di confronto, via API REST. */
final class StripeIdentityVerifier implements IdentityVerifier
{
    public function __construct(private readonly string $secretKey) {}

    public function name(): string
    {
        return 'stripe_identity';
    }

    public function available(): bool
    {
        return $this->secretKey !== '';
    }

    public function start(Consultant $consultant, string $returnUrl): IdentityStart
    {
        try {
            $r = Http::asForm()->withToken($this->secretKey)->timeout(15)->post('https://api.stripe.com/v1/identity/verification_sessions', [
                'type' => 'document',
                'options' => ['document' => ['require_matching_selfie' => 'true', 'require_live_capture' => 'true']],
                'metadata' => ['consultant_id' => (string) $consultant->id, 'code' => (string) $consultant->code],
                'return_url' => $returnUrl,
            ]);
        } catch (Throwable) {
            throw new OnboardingException('Il servizio di verifica non risponde: riprova tra poco.');
        }
        if (! $r->successful() || ! $r->json('url')) {
            throw new OnboardingException('Il servizio di verifica ha rifiutato la richiesta: riprova o carica i documenti.');
        }

        return new IdentityStart((string) $r->json('id'), (string) $r->json('url'));
    }
}
