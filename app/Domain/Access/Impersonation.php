<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * «Accedi come»: il super_admin entra nel portale con l'account di un altro utente registrato (incaricato, cliente, admin) per vedere
 * ciò che vede lui e aiutarlo. È l'unico a poterlo fare; ogni ingresso e ogni ritorno finiscono nel log di audit, e finché è attivo
 * restano bloccati pagamenti, password, firme e dati bancari (middleware «impersonation.safe»): si guarda e si assiste, non si agisce al posto suo.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public static function active(?Request $request = null): bool
    {
        $request ??= request();

        return $request->hasSession() && $request->session()->has(self::SESSION_KEY);
    }

    /** Il super_admin che sta guardando il portale come un altro utente. */
    public static function impersonator(?Request $request = null): ?User
    {
        $request ??= request();
        if (! self::active($request)) {
            return null;
        }

        return User::withoutGlobalScopes()->find((int) $request->session()->get(self::SESSION_KEY));
    }

    public static function canImpersonate(?User $admin, User $target): bool
    {
        return $admin !== null && $admin->isSuperAdmin() && $admin->getKey() !== $target->getKey();
    }

    public static function start(Request $request, User $admin, User $target): void
    {
        abort_unless(self::canImpersonate($admin, $target) && ! self::active($request), 403);
        AuditLog::record('impersonation.started', "{$admin->email} accede come {$target->email}", $target, ['da' => $admin->email, 'come' => $target->email]);
        $request->session()->put(self::SESSION_KEY, $admin->getKey());
        Auth::guard('web')->login($target);
        $request->session()->regenerate();
    }

    /** Torna al proprio account; restituisce il super_admin ritrovato (null se la sessione non era un accesso «come»). */
    public static function stop(Request $request): ?User
    {
        $admin = self::impersonator($request);
        if ($admin === null || ! $admin->isSuperAdmin()) {
            $request->session()->forget(self::SESSION_KEY);

            return null;
        }
        $as = $request->user();
        AuditLog::record('impersonation.stopped', "{$admin->email} torna al proprio account (era {$as?->email})", $as, ['da' => $admin->email, 'come' => $as?->email]);
        $request->session()->forget(self::SESSION_KEY);
        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return $admin;
    }
}
