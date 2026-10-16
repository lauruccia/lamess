<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\IvdContract;
use App\Notifications\SignatureOtpCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Firma elettronica semplice con codice monouso (OTP) inviato per email all'indirizzo dell'account.
 * Il codice non viene mai mostrato a schermo né scritto in chiaro: in cache resta solo la sua impronta (HMAC).
 * Vale 15 minuti, 5 tentativi, un nuovo invio non prima di 60 secondi. Se l'invio email è spento non parte nulla e si dice chiaramente.
 */
final class EmailSignatureProvider implements SignatureProvider
{
    public const MINUTES = 15;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public function name(): string
    {
        return 'email';
    }

    public function request(IvdContract $contract): SignatureRequest
    {
        if (! config('lamess.mail_enabled')) {
            throw new OnboardingException('L\'invio del codice per email non è attivo al momento: riprova più tardi o contatta l\'assistenza.');
        }
        $user = $contract->user;
        if ($user === null || blank($user->email)) {
            throw new OnboardingException('Manca l\'indirizzo email a cui inviare il codice.');
        }
        $key = "sig-email-sent:{$contract->id}";
        if (Cache::has($key)) {
            throw new OnboardingException('Il codice è stato appena inviato: controlla la posta (anche lo spam) e attendi un minuto prima di chiederne un altro.');
        }

        $reference = 'email-'.Str::uuid();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("sig:{$reference}", ['hash' => self::fingerprint($reference, $code), 'attempts' => 0], now()->addMinutes(self::MINUTES));
        Cache::put($key, true, now()->addSeconds(self::RESEND_SECONDS));
        // un nuovo codice annulla il precedente
        if ($contract->signature_ref !== null && str_starts_with($contract->signature_ref, 'email-')) {
            Cache::forget("sig:{$contract->signature_ref}");
        }

        $user->notify(new SignatureOtpCode($code, self::MINUTES));

        return new SignatureRequest($reference, 'Ti abbiamo inviato un codice a '.self::mask((string) $user->email).'. Vale '.self::MINUTES.' minuti.');
    }

    public function verify(string $reference, string $code): bool
    {
        $s = Cache::get("sig:{$reference}");
        if (! is_array($s) || $s['attempts'] >= self::MAX_ATTEMPTS) {
            return false;
        }
        if (! hash_equals($s['hash'], self::fingerprint($reference, trim($code)))) {
            $s['attempts']++;
            Cache::put("sig:{$reference}", $s, now()->addMinutes(self::MINUTES));

            return false;
        }
        Cache::forget("sig:{$reference}");

        return true;
    }

    private static function fingerprint(string $reference, string $code): string
    {
        return hash_hmac('sha256', $reference.'|'.$code, (string) config('app.key'));
    }

    /** m***@dominio.it */
    private static function mask(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
