<?php

declare(strict_types=1);

namespace App\Domain\Succession;

use App\Domain\Audit\AuditLog;
use App\Domain\Customers\CustomerService;
use App\Domain\Network\NetworkException;
use App\Domain\Network\NetworkService;
use App\Models\Consultant;
use App\Models\IdentityVerification;
use App\Models\IvdContract;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cambio di intestatario di un codice attività, per cessione (Reg. 9.1) o successione (Reg. 9.2).
 * Il codice, la posizione nella rete, la qualifica e lo storico restano; cambiano persona, dati bancari, KYC e Lettera IVD.
 * Finché il nuovo intestatario non firma e il Consorzio non accetta la nuova lettera, il codice resta «sospeso».
 */
final class AccountHolderService
{
    public function __construct(private readonly NetworkService $network, private readonly CustomerService $customers) {}

    /** Il nuovo intestatario è utilizzabile? Restituisce il motivo se no. */
    public function assigneeProblem(User $to, ?Consultant $current = null): ?string
    {
        $p = $to->profile;
        if ($p === null || $p->type !== 'private') {
            return 'Il nuovo intestatario deve essere una persona fisica con il profilo compilato.';
        }
        foreach (['first_name', 'last_name', 'tax_code', 'birth_date', 'birth_place', 'address', 'city', 'zip'] as $f) {
            if (blank($p->{$f})) {
                return 'Al nuovo intestatario mancano dati anagrafici obbligatori ('.$f.').';
            }
        }
        if ($p->birth_date->greaterThan(now()->subYears((int) config('lamess.adult_age')))) {
            return 'Il nuovo intestatario deve essere maggiorenne.';
        }
        if ($to->consultant()->exists() && $to->consultant?->id !== $current?->id) {
            return 'Il nuovo intestatario è già (o è stato) un Incaricato: la cessione è possibile solo verso chi non lo è.';
        }
        if (IvdContract::where('user_id', $to->id)->whereIn('status', ['awaiting_signature', 'signed', 'accepted'])->exists()) {
            return 'Il nuovo intestatario ha già una domanda o un incarico in corso.';
        }
        try {
            $this->network->assertReenrollmentAllowed(mb_strtoupper($p->tax_code), now());
        } catch (NetworkException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * @param  string  $kind  transfer | succession
     * @param  User|null  $from  precedente intestatario (per la cessione diventa cliente; per il decesso non si crea nulla)
     */
    public function swap(Consultant $c, User $to, string $kind, string $reason, string $by, ?User $from = null): IvdContract
    {
        if ($c->status === 'terminated') {
            throw new SuccessionException('Il codice è cessato: non si può trasferire.');
        }
        if (($problem = $this->assigneeProblem($to, $c)) !== null) {
            throw new SuccessionException($problem);
        }

        return DB::transaction(function () use ($c, $to, $kind, $reason, $by, $from): IvdContract {
            $profile = $to->profile;
            $oldContract = IvdContract::where('consultant_id', $c->id)->orderByDesc('id')->first();
            IvdContract::where('consultant_id', $c->id)->whereIn('status', ['awaiting_signature', 'signed', 'accepted'])->update(['status' => 'transferred']);
            $c->kycDocuments()->whereIn('status', ['pending', 'approved', 'rejected'])->update(['status' => 'superseded']);
            IdentityVerification::where('consultant_id', $c->id)->update(['status' => 'superseded']);

            // la colonna user_id è unica: libera il vecchio intestatario prima di assegnare il nuovo
            $oldUserId = $c->user_id;
            $c->forceFill(['user_id' => null])->save();
            $c->forceFill([
                'user_id' => $to->id, 'name' => $profile->fullName(), 'person_key' => mb_strtoupper($profile->tax_code),
                'iban' => null, 'iban_holder' => null, 'kyc_status' => 'none', 'onboarding_complete' => false, 'onboarding_completed_at' => null,
                'badge_path' => null, 'fiscal_regime' => 'occasional',
            ])->save();
            if ($c->status === 'active') {
                $this->network->changeStatus($c, 'suspended', "Cambio intestatario ({$kind}): in attesa della nuova Lettera IVD", null, $by);
            }
            $new = IvdContract::create([
                'user_id' => $to->id, 'consultant_id' => $c->id, 'sponsor_id' => $c->sponsor_id, 'kind' => $kind,
                'template_version' => app(\App\Domain\Onboarding\IvdLetterTemplate::class)->versionLabel(), 'status' => 'awaiting_signature', 'term_index' => $oldContract?->term_index ?? 1,
            ]);
            if ($from !== null && $kind === 'transfer') {
                $this->customers->ensureCustomer($from, $this->network->effectiveSponsor($c));
            }
            AuditLog::record("account.{$kind}", "Codice {$c->code}: nuovo intestatario ({$kind})", $c, ['da_user' => $oldUserId, 'a_user' => $to->id], $reason);

            return $new;
        });
    }
}
