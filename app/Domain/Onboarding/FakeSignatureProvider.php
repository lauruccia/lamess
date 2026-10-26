<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\IvdContract;
use App\Models\IvdLetterSignature;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Driver di sviluppo: genera un OTP e lo restituisce nel suggerimento. NON usare in produzione. */
final class FakeSignatureProvider implements SignatureProvider
{
    private const MAX_ATTEMPTS = 5;

    public function name(): string
    {
        return 'fake';
    }

    public function request(IvdContract|IvdLetterSignature $contract): SignatureRequest
    {
        $ref = 'fake-'.Str::uuid();
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("sig:{$ref}", ['otp' => $otp, 'attempts' => 0], now()->addMinutes(15));

        return new SignatureRequest($ref, "Driver fake — codice OTP: {$otp}");
    }

    public function verify(string $reference, string $code): bool
    {
        $s = Cache::get("sig:{$reference}");
        if ($s === null || $s['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (! hash_equals($s['otp'], trim($code))) {
            $s['attempts']++;
            Cache::put("sig:{$reference}", $s, now()->addMinutes(15));

            return false;
        }
        Cache::forget("sig:{$reference}");

        return true;
    }
}
