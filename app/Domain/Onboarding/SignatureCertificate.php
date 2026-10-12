<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\IvdContract;
use Barryvdh\DomPDF\Facade\Pdf;

/** Certificato di completamento della firma della Lettera di incarico: firmatario, impronta del documento, evidenze e registro accessi. */
final class SignatureCertificate
{
    public function pdf(IvdContract $contract): string
    {
        $contract->loadMissing('user.profile', 'consultant', 'events');

        return Pdf::loadView('pdf.signature-certificate', [
            'c' => $contract, 'e' => (array) $contract->evidence, 'events' => $contract->events,
            'company' => config('lamess.company'),
        ])->output();
    }
}
