<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\IvdContract;
use App\Models\IvdLetterSignature;

/** Firma elettronica della Lettera di Incarico (provider reali: Yousign/Namirial/InfoCert, da integrare). */
interface SignatureProvider
{
    public function name(): string;

    public function request(IvdContract|IvdLetterSignature $contract): SignatureRequest;

    public function verify(string $reference, string $code): bool;
}
