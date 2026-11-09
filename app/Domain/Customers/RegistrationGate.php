<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Consultant;
use App\Models\MembershipApplication;
use App\Models\ReferralInvite;
use Illuminate\Http\Request;

/**
 * Registrazione solo su invito. Con l'interruttore «Registrazione solo su invito» (Impostazioni di sistema → Sito) acceso,
 * ci si registra soltanto se si arriva da un link referral valido, da un invito personale o con un cookie referral ancora
 * valido. L'incaricato «casa» non conta come invito: è solo il destinatario di chi si registra senza sponsor.
 */
final class RegistrationGate
{
    public function __construct(private readonly ReferralResolver $resolver, private readonly ApplicationService $applications) {}

    public static function inviteOnly(): bool
    {
        return (bool) config('lamess.registration_invite_only');
    }

    /** True se i pulsanti «Registrati» possono comparire a chi sta guardando il sito (aperta a tutti, o ha già un invito nel browser). */
    public static function linksVisible(): bool
    {
        if (! self::inviteOnly()) {
            return true;
        }
        $request = request();

        return filled($request->cookie((string) config('lamess.referral_cookie_name'))) || filled($request->cookie((string) config('lamess.referral_invite_cookie_name')));
    }

    /** L'incaricato che ha invitato questa persona: dal link (?ref=), dal cookie referral o dall'invito personale. Null se non c'è. */
    public function sponsor(Request $request, ?string $link = null): ?Consultant
    {
        $sponsor = $this->resolver->findByReferralCode($link ?? $request->query('ref')) ?? $this->resolver->findByReferralCode($request->cookie((string) config('lamess.referral_cookie_name')));
        if ($sponsor !== null) {
            return $sponsor;
        }
        $token = $request->cookie((string) config('lamess.referral_invite_cookie_name'));
        if (blank($token)) {
            return null;
        }
        $invite = ReferralInvite::where('token', $token)->whereNull('registered_user_id')->with('consultant')->first();

        return $invite?->consultant !== null ? $this->resolver->findByReferralCode($invite->consultant->referral_code) : null;
    }

    /** Candidatura approvata presentata con il suo link personale (?candidatura= o campo del modulo). Null se non c'è o non è più valida. */
    public function application(Request $request): ?MembershipApplication
    {
        $token = $request->input('candidatura', $request->query('candidatura'));

        return is_string($token) ? $this->applications->findUsable($token) : null;
    }
}
