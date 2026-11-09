<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ShopAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Carrello e acquisti: con il negozio «solo dopo l'accesso» chi non è collegato va alla pagina di accesso e poi torna dov'era. */
final class ShopLoginRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ShopAccess::canBuy()) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Accedi per acquistare.', 'login' => '/accedi'], 401);
        }

        return redirect()->guest('/accedi');
    }
}
