<?php

declare(strict_types=1);

namespace App\Domain\Network;

use App\Domain\Access\PasswordResetLinks;
use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\InvitoAccessoIt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

/**
 * Accesso al portale degli incaricati creati dall'admin: l'iscrizione in rete (NetworkService::enroll) scrive solo la
 * scheda; qui si crea (o si collega) l'utente con cui l'incaricato entra da /accedi e gli si manda l'invito
 * per scegliere la password.
 */
final class ConsultantAccess
{
    /** Esito dell'ultimo invito mandato da grant(): true se l'email è partita. */
    public bool $inviteSent = false;

    /**
     * Dà l'accesso all'incaricato: crea l'utente con quell'email, oppure collega quello già esistente (es. un cliente
     * registrato dal link), e assegna il ruolo «consultant». Non tocca mai utenti del back-office.
     *
     * @throws NetworkException
     */
    public function grant(Consultant $consultant, string $email, bool $sendInvite = true): User
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new NetworkException('Email non valida.');
        }
        if ($consultant->user_id !== null) {
            throw new NetworkException("L'incaricato {$consultant->code} ha già un accesso.");
        }
        if ($consultant->status === 'terminated') {
            throw new NetworkException("L'incaricato {$consultant->code} è cessato: non si crea un accesso.");
        }

        $user = DB::transaction(function () use ($consultant, $email): User {
            $existing = User::withoutGlobalScopes()->where('email', $email)->first();
            if ($existing !== null) {
                if ($existing->isBackoffice()) {
                    throw new NetworkException('Questa email appartiene a un utente del back-office: usa un\'altra email.');
                }
                if (Consultant::where('user_id', $existing->id)->where('status', '!=', 'terminated')->exists()) {
                    throw new NetworkException('Questa email è già collegata a un altro incaricato.');
                }
                $user = $existing;
            } else {
                $user = User::create(['name' => $consultant->name, 'email' => $email, 'password' => Str::random(40)]);
                $this->createProfile($user, $consultant);
            }

            $consultant->update(['user_id' => $user->id, 'referral_code' => $consultant->referral_code ?? $this->newReferralCode()]);
            if (! $user->hasRole('consultant')) {
                $user->assignRole('consultant');
            }
            AuditLog::record('consultant.access_granted', "Accesso al portale per l'incaricato {$consultant->code}: {$email}".($existing !== null ? ' (utente già esistente collegato)' : ''), $consultant, ['email' => $email]);

            return $user;
        });

        $this->inviteSent = $sendInvite && $this->sendInvite($user);

        return $user;
    }

    /**
     * Manda l'invito per scegliere la password, se l'invio email è acceso (Impostazioni di sistema).
     * Restituisce false se non è partito (email spente o errore di posta): l'accesso resta creato e l'admin
     * può mandare il link con «Invia reset password».
     */
    public function sendInvite(User $user): bool
    {
        if (! app(PasswordResetLinks::class)->mailEnabled()) {
            return false;
        }
        try {
            $user->notify(new InvitoAccessoIt(Password::broker()->createToken($user)));
        } catch (Throwable $e) {
            report($e);

            return false;
        }
        AuditLog::record('consultant.invite_sent', "Invito di accesso inviato a {$user->email}", $user);

        return true;
    }

    private function createProfile(User $user, Consultant $consultant): void
    {
        $parts = preg_split('/\s+/', trim($consultant->name)) ?: [];
        $first = array_shift($parts);
        Profile::create([
            'user_id' => $user->id, 'type' => 'private', 'first_name' => $first, 'last_name' => $parts === [] ? null : implode(' ', $parts),
            'tax_code' => $consultant->person_key !== null && $consultant->person_key !== '' ? mb_strtoupper($consultant->person_key) : null,
        ]);
    }

    private function newReferralCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Consultant::where('referral_code', $code)->exists());

        return $code;
    }
}
