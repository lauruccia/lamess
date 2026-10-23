<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Durante un «Accedi come» del super_admin non si fanno azioni al posto dell'utente che muovono soldi o identità:
 * pagamenti, KMoney, password, firma della lettera, IBAN e documenti, prelievi, cessioni.
 */
final class BlockWhileImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::active($request)) {
            $message = 'Stai guardando il portale come un altro utente: questa azione (pagamenti, password, firme, dati bancari) è bloccata. Torna al tuo account per farla.';
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }

            return back()->withErrors(['order' => $message, 'kmoney' => $message, 'impersonation' => $message])->with('status', null);
        }

        return $next($request);
    }
}
