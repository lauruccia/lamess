<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\IvdContract;

/** Firma elettronica della Lettera di Incarico (provider reali: Yousign/Namirial/InfoCert, da integrare). */
interface SignatureProvider
{
    public function name(): string;

    public function request(IvdContract $contract): SignatureRequest;

    public function verify(string $reference, string $code): bool;
}
