<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Impersonation;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sito chiuso: finché «Sito aperto al pubblico» (Impostazioni di sistema) è spento, i visitatori vedono la pagina
 * «In arrivo» con stato 503. Restano raggiungibili il back-office, gli aggiornamenti interni, i webhook dei pagamenti
 * e l'installazione guidata; chi ha già un accesso al back-office vede il sito normalmente, per poterlo provare.
 */
final class SiteClosed
{
    /** Percorsi che non si chiudono mai. */
    private const ALWAYS_OPEN = ['admin', 'admin/*', 'admin-files/*', 'livewire/*', 'up', 'webhooks/*', 'installazione', 'css/*', 'js/*', 'img/*', 'fonts/*', 'favicon.ico', 'robots.txt'];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('site.open') || $request->is(...self::ALWAYS_OPEN)) {
            return $next($request);
        }
        $user = $request->user();
        if (Impersonation::active($request) || ($user instanceof User && $user->isBackoffice())) {
            return $next($request);
        }

        return response()->view('site-closed', [], 503, ['Retry-After' => '3600', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
