<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\I18n\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware globale, PRIMA del routing: toglie il prefisso di lingua dall'indirizzo («/en/negozio» → «/negozio»)
 * e lo ricorda in `i18n.prefix`, così le rotte restano scritte una sola volta. Il prefisso vale solo davanti a una
 * pagina pubblica (home compresa): «/en/area» non è una pagina e resta com'è (404). Se la lingua non esiste
 * o la pagina non è pubblica non cambia nulla. Se la lingua è in bozza decide ApplyLocale, dopo la sessione.
 */
final class StripLocalePrefix
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = trim($request->getPathInfo(), '/');
        $first = $path === '' ? '' : explode('/', $path)[0];
        if ($first === '' || ! preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $first) || ! in_array($first, Locales::allCodes(), true)) {
            return $next($request);
        }

        $rest = '/'.substr($path, strlen($first) + 1);
        $rest = $rest === '/' ? '/' : rtrim($rest, '/');
        if (! Locales::isPublicPath($rest)) {
            return $next($request);
        }

        $server = $request->server->all();
        $query = (string) ($server['QUERY_STRING'] ?? '');
        $server['REQUEST_URI'] = $rest.($query !== '' ? '?'.$query : '');
        $clone = $request->duplicate(null, null, null, null, null, $server);
        $clone->attributes->set('i18n.prefix', $first);
        app()->instance('request', $clone);

        return $next($clone);
    }
}
