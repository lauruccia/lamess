<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un account sospeso o bannato esce subito, anche se la sessione era già aperta (o il «ricordami» ancora valido):
 * lo si controlla a ogni richiesta, senza aspettare la scadenza della sessione.
 */
final class BlockSuspendedAccounts
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && $user->isSuspended()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return redirect('/accedi')->withErrors(['email' => self::message($user)]);
        }

        return $next($request);
    }

    public static function message(User $user): string
    {
        return $user->suspension_type === 'banned'
            ? 'Questo account è stato bloccato. Per chiarimenti scrivi all\'assistenza.'
            : 'Questo account è sospeso. Per riattivarlo scrivi all\'assistenza.';
    }
}
