<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sospensione e ban di un account (cliente, incaricato o admin).
 *
 *  - «Sospeso»: stop temporaneo, si riattiva quando si vuole. «Bannato»: stop deciso, si riattiva solo con una scelta esplicita.
 *    In entrambi i casi l'utente non può più entrare (sito e back-office), le sessioni aperte si chiudono, i suoi abbonamenti
 *    non vengono più addebitati, il suo link referral smette di funzionare e sparisce dagli elenchi e dagli alberi.
 *  - Non si cancella nulla: ordini, fatture, compensi e storico restano com'erano, e la rete non si rompe (i suoi sotto-incaricati restano
 *    dove sono; negli alberi si vedono collegati al primo sopra di lui ancora visibile).
 *  - Chi può farlo: il super_admin per tutti tranne gli altri super_admin; l'admin col permesso «network.manage» solo per incaricati e
 *    clienti. Nessuno può sospendere se stesso. Ogni sospensione e riattivazione finisce nel log di audit con il motivo.
 */
final class AccountSuspension
{
    public const SUSPENDED = 'suspended';

    public const BANNED = 'banned';

    /** @var array<string, string> */
    public const LABELS = [self::SUSPENDED => 'Sospeso', self::BANNED => 'Bannato'];

    /** @var list<int>|null cache per richiesta degli incaricati nascosti */
    private static ?array $hiddenConsultants = null;

    public static function canManage(?User $actor, User $target): bool
    {
        if ($actor === null || $actor->getKey() === $target->getKey() || $target->isSuperAdmin()) {
            return false;
        }
        if ($actor->isSuperAdmin()) {
            return true;
        }

        return ! $target->isBackoffice() && $actor->can('network.manage');
    }

    public function suspend(User $target, string $type, string $reason, ?User $actor = null): User
    {
        $this->assertAllowed($actor, $target);
        if (! isset(self::LABELS[$type])) {
            throw ValidationException::withMessages(['type' => 'Tipo non valido: scegli sospeso o bannato.']);
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Scrivi il motivo: resta nel log di audit.']);
        }

        DB::transaction(function () use ($target, $type, $reason, $actor): void {
            $target->forceFill([
                'suspended_at' => now(), 'suspension_type' => $type, 'suspension_reason' => Str::limit($reason, 500, ''),
                'suspended_by' => $actor?->getKey(), 'remember_token' => Str::random(60),
            ])->save();
            // chiude subito le sessioni aperte (con il driver database; con altri driver ferma il middleware alla richiesta successiva)
            DB::table('sessions')->where('user_id', $target->getKey())->delete();
        });
        self::forgetCache();
        AuditLog::record('user.suspended', (self::LABELS[$type]).": {$target->email}", $target, ['tipo' => $type], $reason);

        return $target;
    }

    public function reinstate(User $target, ?User $actor = null): User
    {
        $this->assertAllowed($actor, $target);
        if (! $target->isSuspended()) {
            return $target;
        }
        $was = $target->suspension_type;
        $target->forceFill(['suspended_at' => null, 'suspension_type' => null, 'suspension_reason' => null, 'suspended_by' => null])->save();
        self::forgetCache();
        AuditLog::record('user.reinstated', "Account riattivato: {$target->email}", $target, ['era' => $was]);

        return $target;
    }

    /** Sottoquery con gli id degli utenti sospesi o bannati (per whereIn / whereNotIn). */
    public static function suspendedUserIds(): QueryBuilder
    {
        return DB::table('users')->whereNotNull('suspended_at')->select('id');
    }

    /**
     * Id degli incaricati il cui account è sospeso o bannato: gli alberi li saltano.
     *
     * @return list<int>
     */
    public static function hiddenConsultantIds(): array
    {
        return self::$hiddenConsultants ??= DB::table('consultants')->whereIn('user_id', self::suspendedUserIds())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function forgetCache(): void
    {
        self::$hiddenConsultants = null;
    }

    /**
     * Padre da mostrare in un albero: salta chi è nascosto e sale al primo sponsor visibile (null = radice della vista).
     *
     * @param  array<int, int|null>  $parentOf  id => sponsor_id
     * @param  array<int, true>  $hidden  id nascosti come chiavi
     */
    public static function visibleParent(array $parentOf, array $hidden, ?int $parent): ?int
    {
        $guard = 0;
        while ($parent !== null && isset($hidden[$parent]) && $guard++ < 10000) {
            $parent = isset($parentOf[$parent]) ? (int) $parentOf[$parent] : null;
        }

        return $parent;
    }

    private function assertAllowed(?User $actor, User $target): void
    {
        if (! self::canManage($actor, $target)) {
            throw ValidationException::withMessages(['user' => 'Non puoi sospendere o riattivare questo account.']);
        }
    }
}
