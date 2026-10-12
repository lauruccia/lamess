<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\Consultant;
use App\Models\IdentityVerification;
use App\Notifications\OnboardingCompleted;

/** Onboarding completo (RF-014): foto + documento + dati fiscali + IBAN intestato + KYC verificato. */
final class OnboardingService
{
    public function saveBankDetails(Consultant $c, string $iban, string $holder): Consultant
    {
        if (! Iban::isValid($iban)) {
            throw new OnboardingException('IBAN non valido.');
        }
        if (trim($holder) === '') {
            throw new OnboardingException("Indicare l'intestatario dell'IBAN.");
        }
        $c->update(['iban' => Iban::normalize($iban), 'iban_holder' => trim($holder)]);

        return $this->recompute($c);
    }

    /** Ultimo documento per tipo. @return array<string,\App\Models\KycDocument> */
    public function latestDocuments(Consultant $c): array
    {
        $out = [];
        foreach ($c->kycDocuments()->where('status', '!=', 'superseded')->orderBy('id')->get() as $d) {
            $out[$d->type] = $d;
        }

        return $out;
    }

    /** Elementi mancanti per l'onboarding completo (testo per l'utente). @return list<string> */
    public function missing(Consultant $c): array
    {
        $out = [];
        $docs = $this->latestDocuments($c);
        $types = (array) config('lamess.kyc_document_types');
        foreach ((array) config('lamess.kyc_required_documents') as $t) {
            $d = $docs[$t] ?? null;
            if ($d === null) {
                $out[] = ($types[$t] ?? $t).': da caricare';
            } elseif ($d->status === 'rejected') {
                $out[] = ($types[$t] ?? $t).': rifiutato, da ricaricare';
            } elseif ($d->status === 'pending') {
                $out[] = ($types[$t] ?? $t).': in verifica';
            }
        }
        $p = $c->user?->profile;
        if ($p === null || blank($p->tax_code) || blank($p->address) || blank($p->city) || blank($p->zip)) {
            $out[] = 'Dati fiscali e di residenza';
        }
        if (blank($c->iban) || blank($c->iban_holder)) {
            $out[] = 'IBAN e intestatario';
        }

        return $out;
    }

    public function recompute(Consultant $c): Consultant
    {
        $docs = $this->latestDocuments($c);
        $required = (array) config('lamess.kyc_required_documents');
        $kyc = 'none';
        // la verifica online riuscita vale come documento d'identità approvato; la foto del tesserino resta a parte
        $online = IdentityVerification::where('consultant_id', $c->id)->where('status', 'verified')->exists();
        $approved = fn ($t) => ($docs[$t] ?? null)?->status === 'approved' || ($t === 'id_document' && $online);
        if ($docs !== [] || IdentityVerification::where('consultant_id', $c->id)->exists()) {
            $kyc = 'submitted';
        }
        if (array_filter($required, fn ($t) => ($docs[$t] ?? null)?->status === 'rejected') !== []) {
            $kyc = 'rejected';
        } elseif (array_filter($required, $approved) === $required && $required !== []) {
            $kyc = 'verified';
        }
        $wasComplete = $c->onboarding_complete;
        $c->kyc_status = $kyc;
        $complete = $kyc === 'verified' && $this->missing($c) === [];
        $c->onboarding_complete = $complete;
        if ($complete && $c->onboarding_completed_at === null) {
            $c->onboarding_completed_at = now();
        }
        $c->save();
        if ($complete && ! $wasComplete) {
            $c->user?->notify(new OnboardingCompleted($c));
        }

        return $c;
    }
}
