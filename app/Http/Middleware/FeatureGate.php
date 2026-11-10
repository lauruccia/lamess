<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Features\AreaMenu;
use App\Domain\Features\Feature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controllo lato server delle funzionalità spente da «Funzionalità e viste».
 *  - senza parametri (area riservata): guarda l'indirizzo e rimanda alla panoramica
 *  - con una chiave (`feature:site.shop`): se è spenta rimanda alla home
 * Spegnere non cancella nulla: l'indirizzo diretto non si apre più.
 */
final class FeatureGate
{
    public function handle(Request $request, Closure $next, ?string $key = null): Response
    {
        if ($key !== null) {
            return Feature::enabled($key) ? $next($request) : $this->away($request, '/');
        }

        return AreaMenu::allows($request->path()) ? $next($request) : $this->away($request, '/area');
    }

    private function away(Request $request, string $to): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Questa funzione non è disponibile.'], 404);
        }

        return redirect($to);
    }
}
