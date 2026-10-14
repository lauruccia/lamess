<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLog;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Throwable;

/**
 * Invio del link «scegli una nuova password» da parte dell'admin, a un account o a molti insieme (incaricati e clienti).
 *
 * Regole, tutte verificate dai test:
 *  - la password attuale non cambia e nessuna password viaggia per email: l'utente sceglie la nuova dal link (pagina pubblica);
 *  - gli account del back-office restano fuori (hanno «Nuova password» in Utenti e il recupero del pannello);
 *  - se l'invio email è spento (LAMESS_MAIL_ENABLED=false) non si crea nessun link: lo decide chi chiama con mailEnabled();
 *  - un invio massivo ha un tempo massimo (l'hosting non ha terminale né code): se non basta, dice quanti ne restano e
 *    rilanciandolo con «salta chi ha già ricevuto un link di recente» riparte da chi manca;
 *  - nel log di audit finiscono solo chi ha agito e i conteggi, mai token o link.
 */
final class PasswordResetLinks
{
    public const AUDIENCE_CONSULTANTS = 'consultants';

    public const AUDIENCE_CUSTOMERS = 'customers';

    public const AUDIENCE_BOTH = 'both';

    /** Finestra entro cui un link già inviato conta come «recente». */
    public const RECENT_HOURS = 24;

    /** Motivi per cui un account viene saltato (chiave => testo per l'admin). */
    public const SKIP_LABELS = [
        'backoffice' => 'account del back-office (per loro c\'è «Nuova password» in Utenti)',
        'no_email' => 'senza una email valida',
        'recent' => 'link già inviato nelle ultime '.self::RECENT_HOURS.' ore',
        'throttled' => 'link già inviato da meno di un minuto',
        'not_found' => 'account non trovato',
    ];

    public function mailEnabled(): bool
    {
        return (bool) config('lamess.mail_enabled');
    }

    /**
     * Gli utenti di una categoria, senza doppioni (chi è sia incaricato sia cliente conta una volta).
     *
     * @return list<int>
     */
    public function userIdsFor(string $audience, bool $includeTerminated = false): array
    {
        $ids = [];
        if (in_array($audience, [self::AUDIENCE_CONSULTANTS, self::AUDIENCE_BOTH], true)) {
            $ids = array_merge($ids, Consultant::query()->whereNotNull('user_id')
                ->when(! $includeTerminated, fn ($q) => $q->where('status', '<>', 'terminated'))
                ->pluck('user_id')->all());
        }
        if (in_array($audience, [self::AUDIENCE_CUSTOMERS, self::AUDIENCE_BOTH], true)) {
            $ids = array_merge($ids, Customer::query()->whereNotNull('user_id')->pluck('user_id')->all());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Invia il link di reset a ciascun utente indicato.
     *
     * @param  iterable<int|string|null>  $userIds
     * @param  bool  $skipRecent  salta chi ha già ricevuto un link nelle ultime RECENT_HOURS ore
     * @param  int|null  $budgetSeconds  tempo massimo; null = quanto consente il server
     * @return array{sent: int, failed: int, remaining: int, skipped: array<string, int>}
     */
    public function send(iterable $userIds, bool $skipRecent = false, ?int $budgetSeconds = null): array
    {
        $ids = [];
        foreach ($userIds as $id) {
            if ($id !== null && $id !== '') {
                $ids[(int) $id] = (int) $id;
            }
        }
        $ids = array_values($ids);
        $budget = $budgetSeconds ?? self::serverBudget();
        $start = microtime(true);
        $result = ['sent' => 0, 'failed' => 0, 'remaining' => 0, 'skipped' => []];
        $processed = 0;
        $lastSent = null;

        foreach (array_chunk($ids, 100) as $chunk) {
            $users = User::query()->whereIn('id', $chunk)->get()->keyBy('id');
            $recent = $skipRecent ? $this->recentlySent($users->pluck('email')->filter()->all()) : [];

            foreach ($chunk as $id) {
                if (microtime(true) - $start > $budget) {
                    break 2;
                }
                $processed++;
                $user = $users->get($id);
                $reason = $this->skipReason($user, $recent);
                if ($reason !== null) {
                    $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;

                    continue;
                }
                $outcome = $this->sendOne($user);
                if ($outcome === 'sent') {
                    $result['sent']++;
                    $lastSent = $user;
                } elseif ($outcome === 'throttled') {
                    $result['skipped']['throttled'] = ($result['skipped']['throttled'] ?? 0) + 1;
                } else {
                    $result['failed']++;
                }
            }
        }
        $result['remaining'] = count($ids) - $processed;
        $this->audit($result, count($ids), $lastSent);

        return $result;
    }

    /** @param  array<string, true>  $recent  email (minuscola) => true */
    private function skipReason(?User $user, array $recent): ?string
    {
        if ($user === null) {
            return 'not_found';
        }
        if ($user->isBackoffice()) {
            return 'backoffice';
        }
        if (! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            return 'no_email';
        }

        return isset($recent[mb_strtolower($user->email)]) ? 'recent' : null;
    }

    /** @return 'sent'|'throttled'|'failed' */
    private function sendOne(User $user): string
    {
        try {
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (Throwable $e) {
            Log::warning('Invio link reset password non riuscito', ['user_id' => $user->getKey(), 'errore' => $e->getMessage()]);

            return 'failed';
        }

        return match ($status) {
            Password::RESET_LINK_SENT => 'sent',
            Password::RESET_THROTTLED => 'throttled',
            default => 'failed',
        };
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, true>
     */
    private function recentlySent(array $emails): array
    {
        if ($emails === []) {
            return [];
        }
        $since = Carbon::now()->subHours(self::RECENT_HOURS);
        $found = DB::table(config('auth.passwords.'.config('auth.defaults.passwords').'.table'))
            ->whereIn('email', $emails)->where('created_at', '>=', $since)->pluck('email')->all();

        return array_fill_keys(array_map('mb_strtolower', $found), true);
    }

    /** @param  array{sent: int, failed: int, remaining: int, skipped: array<string, int>}  $result */
    private function audit(array $result, int $requested, ?User $lastSent): void
    {
        if ($result['sent'] === 0) {
            return;
        }
        if ($requested === 1 && $lastSent !== null) {
            AuditLog::record('user.reset_link_sent', "Link di reset password inviato dall'admin: {$lastSent->email}", $lastSent);

            return;
        }
        AuditLog::record('user.reset_link_batch', "Link di reset password inviati dall'admin a {$result['sent']} account", null, [
            'richiesti' => $requested, 'inviati' => $result['sent'], 'non_riusciti' => $result['failed'],
            'saltati' => array_sum($result['skipped']), 'rimasti' => $result['remaining'],
        ]);
    }

    private static function serverBudget(): int
    {
        $max = (int) ini_get('max_execution_time');

        return $max <= 0 ? 120 : max(10, $max - 10);
    }
}
