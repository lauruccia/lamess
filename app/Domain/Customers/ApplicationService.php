<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use App\Models\MembershipApplication;
use App\Models\User;
use App\Notifications\MembershipApplicationApproved;
use App\Notifications\MembershipApplicationRejected;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Candidature da «Candidati»: la persona lascia i dati, il Consorzio approva o rifiuta, chi è approvato riceve un link
 * personale (monouso, a scadenza) per registrarsi. L'account nasce solo con la registrazione dal link.
 */
final class ApplicationService
{
    public const LINK_DAYS = 30;

    /** @param  array<string,mixed>  $data  first_name, last_name, email, phone, city, province, interest, message */
    public function submit(array $data, ?string $ip = null): MembershipApplication
    {
        $email = mb_strtolower(trim((string) $data['email']));

        return MembershipApplication::create([
            'first_name' => trim((string) $data['first_name']), 'last_name' => trim((string) $data['last_name']), 'email' => $email,
            'phone' => trim((string) $data['phone']), 'city' => trim((string) $data['city']), 'province' => mb_strtoupper(trim((string) $data['province'])),
            'interest' => $data['interest'], 'message' => filled($data['message'] ?? null) ? trim((string) $data['message']) : null,
            'privacy_at' => now(), 'status' => 'pending', 'ip' => $ip,
        ]);
    }

    /** True se per questa email c'è già una candidatura in corso (da valutare o approvata e non ancora usata). */
    public function hasOpen(string $email): bool
    {
        return MembershipApplication::where('email', mb_strtolower(trim($email)))->whereIn('status', ['pending', 'approved'])->exists();
    }

    public function url(MembershipApplication $a): string
    {
        return url('/registrazione?candidatura='.$a->token);
    }

    public function approve(MembershipApplication $a, ?Consultant $sponsor, ?string $note, ?User $by): MembershipApplication
    {
        if (! in_array($a->status, ['pending', 'approved'], true)) {
            throw new InvalidArgumentException('Questa candidatura è già stata chiusa.');
        }
        if (User::withoutGlobalScopes()->where('email', $a->email)->exists()) {
            throw new InvalidArgumentException('Esiste già un account con questa email: la persona può accedere o recuperare la password.');
        }
        $a->forceFill([
            'status' => 'approved', 'sponsor_id' => $sponsor?->id, 'decision_note' => filled($note) ? trim((string) $note) : null,
            'token' => Str::random(48), 'token_expires_at' => now()->addDays(self::LINK_DAYS),
            'reviewed_by' => $by?->id, 'reviewed_at' => now(), 'email_sent_at' => null, 'email_note' => null,
        ])->save();
        AuditLog::record('application.approve', "Candidatura di {$a->fullName()} approvata", $a);
        $this->sendDecision($a);

        return $a->refresh();
    }

    public function reject(MembershipApplication $a, ?string $reason, ?User $by): MembershipApplication
    {
        if ($a->status !== 'pending') {
            throw new InvalidArgumentException('Si può rifiutare solo una candidatura ancora da valutare.');
        }
        $a->forceFill([
            'status' => 'rejected', 'decision_note' => filled($reason) ? trim((string) $reason) : null, 'token' => null, 'token_expires_at' => null,
            'reviewed_by' => $by?->id, 'reviewed_at' => now(), 'email_sent_at' => null, 'email_note' => null,
        ])->save();
        AuditLog::record('application.reject', "Candidatura di {$a->fullName()} rifiutata", $a);
        $this->sendDecision($a);

        return $a->refresh();
    }

    /** Rimanda l'email della decisione (per approvata con link rigenerato e prorogato; per rifiutata com'era). */
    public function resend(MembershipApplication $a): MembershipApplication
    {
        if (! in_array($a->status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Non c\'è nessuna decisione da comunicare.');
        }
        if ($a->status === 'approved') {
            $a->forceFill(['token_expires_at' => now()->addDays(self::LINK_DAYS)])->save();
        }
        $this->sendDecision($a);

        return $a->refresh();
    }

    /** Invio solo con «Invio email attivo»: spento, l'esito resta nel pannello e si può rimandare dopo. */
    private function sendDecision(MembershipApplication $a): void
    {
        if (! config('lamess.mail_enabled')) {
            $a->forceFill(['email_note' => 'Invio email spento: l\'esito non è stato comunicato.'])->save();

            return;
        }
        $notification = $a->status === 'approved' ? new MembershipApplicationApproved($a) : new MembershipApplicationRejected($a);
        Notification::route('mail', [$a->email => $a->fullName()])->notify($notification);
        $a->forceFill(['email_sent_at' => now(), 'email_note' => null])->save();
    }

    /** Candidatura utilizzabile per questo token (approvata, non usata, non scaduta). */
    public function findUsable(?string $token): ?MembershipApplication
    {
        if (blank($token)) {
            return null;
        }
        $a = MembershipApplication::where('token', (string) $token)->with('sponsor')->first();

        return $a !== null && $a->isUsable() ? $a : null;
    }

    public function markRegistered(MembershipApplication $a, User $user): void
    {
        $a->forceFill(['status' => 'registered', 'token' => null, 'token_expires_at' => null, 'registered_user_id' => $user->id, 'registered_at' => now()])->save();
    }
}
