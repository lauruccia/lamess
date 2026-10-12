<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Domain\Customers\CustomerService;
use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Models\Consultant;
use App\Models\IvdContract;
use App\Models\IvdContractEvent;
use App\Models\Order;
use App\Models\PvMovement;
use App\Models\Sanction;
use App\Models\User;
use App\Notifications\ApplicationRejected;
use App\Notifications\WelcomeConsultant;
use Barryvdh\DomPDF\Facade\Pdf;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Domanda di incarico IVD: richiesta → firma → creazione immediata dell'identità → accettazione/rifiuto (RF-010…016). */
final class IvdService
{
    public function __construct(
        private readonly NetworkService $network,
        private readonly SignatureProvider $signature,
        private readonly CustomerService $customers,
        private readonly BadgeService $badges,
    ) {}

    /** @param array<string,mixed> $profileData dati anagrafici obbligatori per la lettera */
    public function apply(User $user, array $profileData, ?Consultant $sponsor): IvdContract
    {
        $profile = $user->profile ?? throw new OnboardingException('Profilo mancante.');
        if ($profile->type !== 'private') {
            throw new OnboardingException('Solo le persone fisiche possono diventare Incaricati.');
        }
        $profile->update($profileData);
        $profile->refresh();
        foreach (['first_name', 'last_name', 'tax_code', 'birth_date', 'birth_place', 'address', 'city', 'zip'] as $f) {
            if (blank($profile->{$f})) {
                throw new OnboardingException("Dato obbligatorio mancante: {$f}.");
            }
        }
        $minBirth = now()->subYears((int) config('lamess.adult_age'));
        if ($profile->birth_date->greaterThan($minBirth)) {
            throw new OnboardingException('L\'Incaricato deve essere maggiorenne.');
        }
        if ($user->consultant()->whereNot('status', 'terminated')->exists() || IvdContract::where('user_id', $user->id)->whereIn('status', ['awaiting_signature', 'signed', 'accepted'])->exists()) {
            throw new OnboardingException('Esiste già una domanda o un incarico per questo utente.');
        }
        try {
            $this->network->assertReenrollmentAllowed(mb_strtoupper($profile->tax_code), now());
        } catch (NetworkException $e) {
            throw new OnboardingException($e->getMessage(), 0, $e);
        }
        if (! $this->hasPaidFirstBox($user)) {
            throw new OnboardingException('Per diventare Incaricato devi prima acquistare la tua prima box.');
        }
        $sponsor ??= $user->customer?->consultant;
        if ($sponsor !== null && ! in_array($sponsor->status, (array) config('lamess.sponsoring_statuses'), true)) {
            throw new OnboardingException('Lo sponsor indicato non può sponsorizzare.');
        }

        return IvdContract::create([
            'user_id' => $user->id, 'sponsor_id' => $sponsor?->id,
            'template_version' => (string) config('lamess.ivd_template_version'), 'status' => 'awaiting_signature',
        ]);
    }

    /** Vero se la prima box non è richiesta (impostazione spenta) oppure l'utente ha almeno un ordine pagato e non annullato/rimborsato. */
    public function hasPaidFirstBox(User $user): bool
    {
        if (! config('lamess.require_first_box_to_apply')) {
            return true;
        }

        return Order::query()->where('buyer_user_id', $user->id)->whereNotNull('paid_at')
            ->whereNotIn('status', ['cancelled', 'refunded'])->exists();
    }

    /** Registra un accesso alla lettera (visualizzazione, firma, scarico) con IP e browser: finisce nel certificato di completamento. */
    public function logEvent(IvdContract $c, string $event, ?string $ip = null, ?string $userAgent = null): void
    {
        IvdContractEvent::create([
            'ivd_contract_id' => $c->id, 'event' => $event, 'after_signing' => $c->signed_at !== null, 'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null, 'occurred_at' => now(),
        ]);
    }

    public function requestSignature(IvdContract $c): SignatureRequest
    {
        $this->assertStatus($c, 'awaiting_signature');
        $req = $this->signature->request($c);
        $c->update(['signature_provider' => $this->signature->name(), 'signature_ref' => $req->reference]);

        return $req;
    }

    /** Firma con OTP: crea il PDF, l'identità di Incaricato (attiva) e i riferimenti di firma. */
    public function sign(IvdContract $c, string $otp, ?string $ip = null, ?string $userAgent = null): IvdContract
    {
        $this->assertStatus($c, 'awaiting_signature');
        if ($c->signature_ref === null || ! $this->signature->verify($c->signature_ref, $otp)) {
            throw new OnboardingException('Codice OTP non valido o scaduto.');
        }

        return DB::transaction(function () use ($c, $ip, $userAgent): IvdContract {
            $now = new DateTimeImmutable;
            $user = $c->user()->with('profile')->firstOrFail();
            $profile = $user->profile;
            // cessione o successione: il codice resta lo stesso, cambia solo l'intestatario (già impostato)
            $consultant = $c->consultant_id !== null && $c->kind !== 'new'
                ? $c->consultant()->firstOrFail()
                : $this->network->enroll([
                    'code' => $this->nextCode(), 'referral_code' => $this->newReferralCode(), 'name' => $profile->fullName(),
                    'person_key' => mb_strtoupper($profile->tax_code), 'user_id' => $user->id,
                ], $c->sponsor, $now);

            $pdf = Pdf::loadView('pdf.ivd-letter', [
                'contract' => $c, 'profile' => $profile, 'consultant' => $consultant, 'sponsor' => $c->sponsor, 'signedAt' => $now,
                'feeAmount' => config('lamess.annual_fee_amount'), 'firstPaidYear' => config('lamess.annual_fee_first_paid_year'),
            ])->output();
            $path = "contracts/{$c->id}.pdf";
            Storage::put($path, $pdf);
            $hash = hash('sha256', $pdf);

            $c->update([
                'status' => 'signed', 'consultant_id' => $consultant->id, 'signed_at' => $now, 'starts_at' => $now,
                'expires_at' => $now->modify('+'.(int) config('lamess.ivd_term_months').' months'),
                'pdf_path' => $path, 'pdf_hash' => $hash,
                'evidence' => [
                    'provider' => $c->signature_provider, 'reference' => $c->signature_ref, 'ip' => $ip, 'user_agent' => $userAgent, 'signed_at' => $now->format(DATE_ATOM), 'document_sha256' => $hash,
                    'signer_email' => $user->email, 'signer_email_verified_at' => $user->email_verified_at?->format(DATE_ATOM),
                    'created_at' => $c->created_at?->format(DATE_ATOM), 'template_version' => $c->template_version,
                ],
            ]);
            $this->logEvent($c->refresh(), 'signed', $ip, $userAgent);

            return $c->refresh();
        });
    }

    public function accept(IvdContract $c, string $by): IvdContract
    {
        $this->assertStatus($c, 'signed');
        $c->update(['status' => 'accepted', 'accepted_at' => now(), 'accepted_by' => $by]);
        $consultant = $c->consultant;
        if ($c->kind !== 'new' && $consultant->status === 'suspended'
            && ! Sanction::where('consultant_id', $consultant->id)->where('status', 'active')->where('type', 'suspension')->exists()) {
            $this->network->changeStatus($consultant, 'active', 'Nuova Lettera IVD accettata', null, $by);
        }
        $this->badges->generate($consultant);
        $c->user->notify(new WelcomeConsultant($consultant));

        return $c->refresh();
    }

    /**
     * Rifiuto (Reg. 2.7): l'account torna FAN. I punti maturati nel frattempo nei periodi non chiusi restano
     * PV di un cliente assegnato allo sponsor proposto (OPEN-22).
     */
    public function reject(IvdContract $c, string $by, string $reason = ''): IvdContract
    {
        $this->assertStatus($c, 'signed');

        return DB::transaction(function () use ($c, $reason): IvdContract {
            $consultant = $c->consultant;
            $sponsor = $c->sponsor;
            if ($sponsor !== null) {
                PvMovement::where('consultant_id', $consultant->id)
                    ->whereHas('period', fn ($q) => $q->where('status', '!=', 'closed'))
                    ->update(['consultant_id' => $sponsor->id, 'kind' => 'pvf']);
            }
            $this->network->terminate($consultant, 'Domanda rifiutata'.($reason !== '' ? ": {$reason}" : ''));
            $this->customers->ensureCustomer($c->user, $sponsor);
            $c->user->customer?->update(['assigned_consultant_id' => $sponsor?->id]);
            $c->update(['status' => 'rejected', 'rejected_at' => now(), 'rejection_reason' => $reason]);
            $c->user->notify(new ApplicationRejected);

            return $c->refresh();
        });
    }

    private function assertStatus(IvdContract $c, string $expected): void
    {
        if ($c->refresh()->status !== $expected) {
            throw new OnboardingException("Operazione non consentita: la domanda è in stato «{$c->status}».");
        }
    }

    private function nextCode(): string
    {
        $prefix = (string) config('lamess.consultant_code_prefix');
        $max = 0;
        foreach (Consultant::where('code', 'like', $prefix.'%')->pluck('code') as $code) {
            $n = (int) substr($code, strlen($prefix));
            $max = max($max, $n);
        }

        return $prefix.str_pad((string) ($max + 1), (int) config('lamess.consultant_code_width'), '0', STR_PAD_LEFT);
    }

    private function newReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Consultant::where('referral_code', $code)->exists());

        return $code;
    }
}
