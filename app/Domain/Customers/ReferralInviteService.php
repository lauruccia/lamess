<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Models\Consultant;
use App\Models\Customer;
use App\Models\ReferralInvite;
use App\Models\User;
use App\Notifications\ReferralInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Inviti referral con link personale: creazione e invio, tracciamento del click, collegamento alla registrazione.
 * Il link è quello referral dell'incaricato più il token dell'invito: chi lo apre viene assegnato all'incaricato
 * (come sempre) e in più l'invito passa da «inviato» ad «aperto» e poi a «registrato».
 */
final class ReferralInviteService
{
    public const MAX_REMINDERS = 3;

    /** Ore minime fra un invio e il successivo promemoria dello stesso invito. */
    public const REMINDER_GAP_HOURS = 24;

    /** User-agent di anteprime e scanner (WhatsApp, posta, social): il loro click non è della persona. */
    private const BOT_PATTERN = '/bot|crawl|spider|preview|whatsapp|facebookexternalhit|slack|telegram|curl|wget|headless|python-requests|googleimageproxy|safelinks/i';

    public function url(ReferralInvite $invite): string
    {
        return url('/r/'.$invite->consultant->ensureReferralCode()).'?i='.$invite->token;
    }

    /** Link WhatsApp già compilato (con il numero, se c'è). */
    public function whatsappUrl(ReferralInvite $invite): string
    {
        $text = 'Ciao'.($invite->recipient_name ? ' '.$invite->recipient_name : '').', ti invito su Lamèss: registrati da qui '.$this->url($invite);
        $digits = $this->phoneDigits($invite->phone);

        return 'https://wa.me/'.($digits !== '' ? $digits : '').'?text='.rawurlencode($text);
    }

    /**
     * @param  array{channel:string, email?:?string, phone?:?string, recipient_name?:?string}  $data
     *
     * @throws ReferralInviteException
     */
    public function create(Consultant $consultant, array $data): ReferralInvite
    {
        $channel = $data['channel'];
        $email = filled($data['email'] ?? null) ? mb_strtolower(trim((string) $data['email'])) : null;
        $phone = filled($data['phone'] ?? null) ? trim((string) $data['phone']) : null;

        $this->expireOverdue($consultant);
        $perDay = (int) config('lamess.referral_invites_per_day');
        if ($perDay > 0 && ReferralInvite::where('consultant_id', $consultant->id)->where('created_at', '>=', now()->subDay())->count() >= $perDay) {
            throw new ReferralInviteException("Hai raggiunto il limite di {$perDay} inviti nelle ultime 24 ore. Riprova più tardi.");
        }
        if ($email !== null) {
            if (User::where('email', $email)->exists()) {
                throw new ReferralInviteException('Questa persona è già iscritta a Lamèss: non serve invitarla.');
            }
            $open = ReferralInvite::where('consultant_id', $consultant->id)->where('email', $email)->whereIn('status', ['created', 'sent', 'opened', 'failed'])->latest('id')->first();
            if ($open !== null) {
                throw new ReferralInviteException('Hai già invitato questa email: usa «Ricorda» sull\'invito esistente.');
            }
        }

        $invite = ReferralInvite::create([
            'consultant_id' => $consultant->id, 'token' => $this->newToken(), 'channel' => $channel,
            'recipient_name' => filled($data['recipient_name'] ?? null) ? trim((string) $data['recipient_name']) : null,
            'email' => $email, 'phone' => $phone, 'status' => 'created',
            'expires_at' => now()->addDays($this->days()),
        ]);

        if ($channel === 'email') {
            $this->sendEmail($invite);
        } elseif ($channel === 'whatsapp') {
            // l'invio avviene dal telefono dell'incaricato: il portale registra solo che l'ha dichiarato
            $invite->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_sent_at' => now()])->save();
        }

        return $invite->refresh();
    }

    /**
     * Manda (o rimanda) l'email dell'invito. Con la posta spenta l'invito resta «creato» e dice perché.
     *
     * @return bool true se l'email è partita
     */
    public function sendEmail(ReferralInvite $invite, bool $reminder = false): bool
    {
        if (blank($invite->email)) {
            return false;
        }
        if (! config('lamess.mail_enabled')) {
            $invite->forceFill(['failure_reason' => 'Invio email non attivo sul portale: invito creato, ma nessuna email è partita.'])->save();

            return false;
        }
        try {
            Notification::route('mail', [$invite->email => (string) $invite->recipient_name])->notify(new ReferralInvitation($invite, $reminder));
        } catch (Throwable $e) {
            report($e);
            $invite->forceFill(['status' => 'failed', 'failure_reason' => Str::limit($e->getMessage(), 190, '')])->save();

            return false;
        }
        $invite->forceFill([
            'status' => in_array($invite->status, ['created', 'failed', 'expired'], true) ? 'sent' : $invite->status,
            'sent_at' => $invite->sent_at ?? now(), 'last_sent_at' => now(), 'failure_reason' => null,
        ])->save();

        return true;
    }

    /**
     * «Ricorda» / «Reinvia»: rimanda l'email (o, per WhatsApp e link, registra il nuovo invio) e riparte la scadenza.
     *
     * @throws ReferralInviteException
     */
    public function remind(ReferralInvite $invite): bool
    {
        if ($invite->status === 'registered') {
            throw new ReferralInviteException('Questa persona si è già registrata.');
        }
        if ($invite->reminders_count >= self::MAX_REMINDERS) {
            throw new ReferralInviteException('Hai già mandato '.self::MAX_REMINDERS.' promemoria a questa persona.');
        }
        if ($invite->last_sent_at !== null && $invite->last_sent_at->gt(now()->subHours(self::REMINDER_GAP_HOURS))) {
            throw new ReferralInviteException('Hai mandato questo invito da meno di 24 ore: riprova più tardi.');
        }
        $sent = true;
        if ($invite->channel === 'email') {
            $sent = $this->sendEmail($invite, true);
        } else {
            $invite->forceFill(['last_sent_at' => now(), 'status' => in_array($invite->status, ['created', 'expired'], true) ? 'sent' : $invite->status])->save();
        }
        if ($sent) {
            $invite->forceFill(['reminders_count' => $invite->reminders_count + 1, 'expires_at' => now()->addDays($this->days())])->save();
            if ($invite->status === 'expired') {
                $invite->forceFill(['status' => 'sent'])->save();
            }
        }

        return $sent;
    }

    /** Click sul link personale: l'invito risulta «aperto». Ignora anteprime e scanner. Un invito scaduto riprende vita. */
    public function recordOpen(?string $token, Consultant $consultant, ?string $userAgent): ?ReferralInvite
    {
        if (blank($token)) {
            return null;
        }
        $invite = ReferralInvite::where('token', $token)->where('consultant_id', $consultant->id)->first();
        if ($invite === null || $invite->status === 'registered') {
            return $invite;
        }
        if ($userAgent === null || $userAgent === '' || preg_match(self::BOT_PATTERN, $userAgent) === 1) {
            return $invite;
        }
        $invite->forceFill([
            'status' => 'opened', 'opened_at' => $invite->opened_at ?? now(), 'last_opened_at' => now(),
            'opens_count' => $invite->opens_count + 1, 'expires_at' => now()->addDays($this->days()), 'failure_reason' => null,
        ])->save();

        return $invite;
    }

    /** L'invito della persona che si è appena registrata: prima dal token del link, poi dall'email. */
    public function findForRegistration(?string $token, ?string $email): ?ReferralInvite
    {
        if (filled($token)) {
            $byToken = ReferralInvite::where('token', $token)->whereNull('registered_user_id')->first();
            if ($byToken !== null) {
                return $byToken;
            }
        }
        if (blank($email)) {
            return null;
        }

        return ReferralInvite::where('email', mb_strtolower(trim($email)))->whereNull('registered_user_id')->latest('id')->first();
    }

    public function markRegistered(ReferralInvite $invite, User $user): void
    {
        $invite->forceFill(['status' => 'registered', 'registered_at' => now(), 'registered_user_id' => $user->id, 'failure_reason' => null])->save();
    }

    /** Scade gli inviti non registrati oltre la data di scadenza. */
    public function expireOverdue(?Consultant $consultant = null): int
    {
        return ReferralInvite::query()
            ->when($consultant !== null, fn ($q) => $q->where('consultant_id', $consultant->id))
            ->whereIn('status', ['created', 'sent', 'opened'])->where('expires_at', '<', now())
            ->update(['status' => 'expired']);
    }

    /** @return array{total:int, sent:int, opened:int, registered:int, ordered:int, expired:int, pending:int, direct:int} */
    public function stats(Consultant $consultant): array
    {
        $invites = ReferralInvite::where('consultant_id', $consultant->id);
        $registered = (clone $invites)->where('status', 'registered')->count();
        $ordered = (clone $invites)->ordered()->count();
        $viaInvite = (clone $invites)->whereNotNull('registered_user_id')->pluck('registered_user_id');

        return [
            'total' => (clone $invites)->count(),
            'sent' => (clone $invites)->whereIn('status', ['sent', 'opened', 'registered'])->count(),
            'opened' => (clone $invites)->whereNotNull('opened_at')->count(),
            'registered' => $registered,
            'ordered' => $ordered,
            'expired' => (clone $invites)->where('status', 'expired')->count(),
            'pending' => (clone $invites)->whereIn('status', ['created', 'sent', 'opened'])->count(),
            // registrati dal link generico o dal QR: assegnati a lui senza un invito personale
            'direct' => Customer::where('assigned_consultant_id', $consultant->id)->whereIn('source', ['referral', 'cookie'])->whereNotIn('user_id', $viaInvite)->count(),
        ];
    }

    /** Numero in sole cifre con prefisso internazionale; un cellulare italiano senza prefisso prende 39. */
    public function phoneDigits(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '3') && strlen($digits) >= 9 && strlen($digits) <= 10) {
            $digits = '39'.$digits;
        }

        return $digits;
    }

    private function days(): int
    {
        return max(1, (int) config('lamess.referral_cookie_days'));
    }

    private function newToken(): string
    {
        do {
            $token = Str::lower(Str::random(24));
        } while (ReferralInvite::where('token', $token)->exists());

        return $token;
    }
}
