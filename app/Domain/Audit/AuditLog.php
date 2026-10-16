<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Log di audit: tabella in sola scrittura con catena di hash.
 *
 * Ogni riga contiene hash = HMAC-SHA256( prev_hash | contenuto canonico della riga ). Cambiare, togliere, aggiungere o
 * riordinare una riga rompe la catena e la verifica lo indica con il numero della prima riga compromessa.
 * La chiave dell'HMAC deriva da APP_KEY (o da LAMESS_AUDIT_KEY): chi legge e scrive solo il database non può
 * ricalcolare una catena valida. Se si cambia APP_KEY il log precedente non è più verificabile (come i dati cifrati).
 *
 * Limite noto: cancellare le ULTIME righe non rompe la catena. Per questo la pagina di verifica mostra un'«ancora»
 * (ultimo numero + hash) da annotare fuori dal sistema e poi ricontrollare.
 *
 * Mai scrivere nel log password, segreti, codici 2FA: i valori sensibili vengono rimossi da sanitize().
 */
final class AuditLog
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Chiavi i cui valori non finiscono mai nel log (anche annidate). */
    private const SECRET_KEYS = ['password', 'password_confirmation', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes', 'token', 'secret'];

    private static ?bool $tableExists = null;

    /**
     * Registra un evento. Restituisce l'id della riga, o null se la tabella non esiste ancora (installazione in corso).
     *
     * @param  array<string, mixed>  $changes  dettagli (es. ['email' => ['prima' => …, 'dopo' => …]])
     */
    public static function record(string $action, string $description, Model|array|null $subject = null, array $changes = [], ?string $reason = null): ?int
    {
        if (! self::tableExists()) {
            return null;
        }
        [$actorId, $actorLabel] = self::actor();
        [$subjectType, $subjectId] = self::subject($subject);
        $changes = self::sanitize($changes);

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($action, $description, $subjectType, $subjectId, $changes, $reason, $actorId, $actorLabel): int {
                    $last = DB::table('audit_logs')->orderByDesc('id')->lockForUpdate()->first(['id', 'hash']);
                    $row = [
                        'occurred_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
                        'actor_id' => $actorId,
                        'actor_label' => $actorLabel,
                        'ip' => self::ip(),
                        'action' => $action,
                        'subject_type' => $subjectType,
                        'subject_id' => $subjectId,
                        'description' => $description,
                        'changes' => $changes === [] ? null : self::canonical($changes),
                        'reason' => $reason,
                        'prev_hash' => $last->hash ?? self::GENESIS,
                    ];
                    $row['hash'] = self::hashRow($row);

                    return (int) DB::table('audit_logs')->insertGetId($row);
                });
            } catch (UniqueConstraintViolationException $e) {
                // due scritture contemporanee hanno letto lo stesso ultimo anello: la seconda riprova
                if ($attempt >= 6) {
                    throw new RuntimeException('Impossibile scrivere nel log di audit (conflitto sulla catena).', 0, $e);
                }
                usleep(random_int(2_000, 20_000));
            }
        }
    }

    /** Controlla l'intera catena, riga per riga. */
    public static function verify(): AuditCheck
    {
        if (! self::tableExists()) {
            return new AuditCheck(true, 0, 0, null, null, null, null, false);
        }
        $total = (int) DB::table('audit_logs')->count();
        $head = DB::table('audit_logs')->orderByDesc('id')->first(['id', 'hash']);
        $prev = self::GENESIS;
        $checked = 0;
        $problem = null;
        $badId = null;

        DB::table('audit_logs')->orderBy('id')->chunkById(500, function ($rows) use (&$prev, &$checked, &$problem, &$badId): bool {
            foreach ($rows as $r) {
                $row = (array) $r;
                if ($row['prev_hash'] !== $prev) {
                    $problem = "catena interrotta alla riga #{$row['id']}: mancano o sono state spostate righe precedenti";
                    $badId = (int) $row['id'];

                    return false;
                }
                if (! hash_equals(self::hashRow($row), (string) $row['hash'])) {
                    $problem = "la riga #{$row['id']} è stata modificata";
                    $badId = (int) $row['id'];

                    return false;
                }
                $prev = (string) $row['hash'];
                $checked++;
            }

            return true;
        });

        return new AuditCheck($problem === null, $checked, $total, $badId, $problem, $head?->id !== null ? (int) $head->id : null, $head?->hash, self::dbProtected());
    }

    /** Confronta un'ancora annotata in precedenza («ID:hash») con il log attuale. */
    public static function checkAnchor(string $anchor): ?string
    {
        if (! preg_match('/^(\d+):([0-9a-f]{64})$/', trim($anchor), $m)) {
            return 'Ancora non valida: il formato è «numero:hash».';
        }
        $row = DB::table('audit_logs')->where('id', (int) $m[1])->first(['hash']);
        if ($row === null) {
            return "La riga #{$m[1]} dell'ancora non esiste più: il log è stato accorciato.";
        }

        return hash_equals((string) $row->hash, $m[2]) ? null : "La riga #{$m[1]} è diversa da quella dell'ancora: il log è stato alterato.";
    }

    /** True se il database vieta davvero UPDATE e DELETE sulla tabella (trigger presenti). */
    public static function dbProtected(): bool
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                return (int) DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', ['audit_logs_no_update', 'audit_logs_no_delete'])->count() === 2;
            }
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                return (int) DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
                    ->whereIn('TRIGGER_NAME', ['audit_logs_no_update', 'audit_logs_no_delete'])->count() === 2;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    public static function tableExists(): bool
    {
        // memorizzato per processo; i test lo azzerano con forget()
        return self::$tableExists ??= Schema::hasTable('audit_logs');
    }

    public static function forget(): void
    {
        self::$tableExists = null;
    }

    /** @param array<string, mixed> $row */
    private static function hashRow(array $row): string
    {
        $payload = [
            'v' => 1,
            'at' => $row['occurred_at'],
            'actor_id' => $row['actor_id'] === null ? null : (int) $row['actor_id'],
            'actor' => $row['actor_label'],
            'ip' => $row['ip'],
            'action' => $row['action'],
            'subject_type' => $row['subject_type'],
            'subject_id' => $row['subject_id'],
            'description' => $row['description'],
            'changes' => $row['changes'] === null ? null : json_decode((string) $row['changes'], true),
            'reason' => $row['reason'],
        ];

        return hash_hmac('sha256', $row['prev_hash'].'|'.self::canonical($payload), self::key(isset($row['id']) ? (int) $row['id'] : null));
    }

    /** JSON deterministico: chiavi ordinate ricorsivamente, nessun escape superfluo. */
    private static function canonical(mixed $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(mixed $v): mixed
    {
        if (! is_array($v)) {
            return $v;
        }
        $v = array_map(self::sortKeys(...), $v);
        if (! array_is_list($v)) {
            ksort($v, SORT_STRING);
        }

        return $v;
    }

    /**
     * Chiave dell'HMAC. Le righe nuove usano sempre quella in vigore; in verifica, le righe fino a
     * LAMESS_AUDIT_KEY_PREVIOUS_UNTIL (id) si controllano con la chiave precedente: serve quando il log è nato
     * con un'altra chiave (es. importato da un altro ambiente) e la catena non va né riscritta né spezzata.
     */
    private static function key(?int $rowId = null): string
    {
        $previous = config('lamess.audit_key_previous');
        $until = (int) config('lamess.audit_key_previous_until');
        $usePrevious = $rowId !== null && $until > 0 && $rowId <= $until && is_string($previous) && $previous !== '';
        $configured = $usePrevious ? $previous : config('lamess.audit_key');
        $material = is_string($configured) && $configured !== '' ? $configured : (string) config('app.key');
        if (str_starts_with($material, 'base64:')) {
            $material = (string) base64_decode(substr($material, 7), true);
        }
        if ($material === '') {
            throw new RuntimeException('APP_KEY mancante: il log di audit non può essere firmato.');
        }

        return hash_hkdf('sha256', $material, 32, 'lamess/audit-chain/v1');
    }

    /** @return array{0: int|null, 1: string} */
    private static function actor(): array
    {
        $user = Auth::user();
        if ($user instanceof User) {
            return [(int) $user->getKey(), (string) $user->email];
        }

        return [null, app()->runningInConsole() ? 'console' : 'anonimo'];
    }

    /** @return array{0: string|null, 1: string|null} */
    private static function subject(Model|array|null $subject): array
    {
        if ($subject instanceof Model) {
            return [class_basename($subject), (string) $subject->getKey()];
        }
        if (is_array($subject)) {
            return [(string) ($subject[0] ?? null) ?: null, isset($subject[1]) ? (string) $subject[1] : null];
        }

        return [null, null];
    }

    private static function ip(): ?string
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return null;
        }

        return request()?->ip();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function sanitize(array $data): array
    {
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::SECRET_KEYS, true)) {
                $data[$k] = '[omesso]';
            } elseif (is_array($v)) {
                $data[$k] = self::sanitize($v);
            }
        }

        return $data;
    }
}
