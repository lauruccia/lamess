<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\I18n\Locales;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sceglie la lingua della richiesta, dopo la sessione: prefisso nell'indirizzo, poi cookie, poi preferenza dell'utente,
 * poi italiano. Il back-office resta sempre in italiano. Una lingua in bozza la vede solo chi ha accesso al back-office
 * (anteprima per il traduttore); a tutti gli altri risponde come una pagina inesistente. Nelle lingue diverse
 * dall'italiano i link alle pagine pubbliche si riscrivono con il prefisso, così la navigazione resta nella lingua.
 */
final class ApplyLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $default = Locales::default();
        app()->setFallbackLocale($default);

        if ($this->isBackoffice($request) || $this->isItalianOnly($request)) {
            app()->setLocale($default);
            $request->attributes->set('i18n.forced', true);

            return $next($request);
        }

        $user = $request->user();
        $canPreview = $user instanceof User && $user->isBackoffice();
        $usable = fn (?string $code): bool => is_string($code) && $code !== '' && ($code === $default || Locales::isActive($code) || ($canPreview && in_array($code, Locales::allCodes(), true)));

        $prefix = $request->attributes->get('i18n.prefix');
        if ($prefix !== null && ! $usable($prefix)) {
            abort(404);
        }
        $cookie = $request->cookies->get((string) config('i18n.cookie'));
        $locale = $usable($prefix) ? $prefix : ($usable($cookie) ? $cookie : ($usable($user?->locale ?? null) ? $user->locale : $default));

        // «/it/negozio» non è l'indirizzo canonico: ricorda la scelta e vai a «/negozio»
        if ($prefix === $default && $request->isMethod('GET')) {
            return redirect($request->getRequestUri())->withCookie($this->cookie($default));
        }

        app()->setLocale($locale);
        $response = $next($request);

        if ($prefix !== null && $request->cookies->get((string) config('i18n.cookie')) !== $prefix) {
            $response->headers->setCookie($this->cookie($prefix));
        }
        if ($locale !== $default) {
            $this->localizeLinks($response, $locale);
        }

        return $response;
    }

    /** Area incaricati e pagine personali: per ora solo italiano (le pagine del negozio dentro /area si traducono). */
    private function isItalianOnly(Request $request): bool
    {
        if ($request->is('i/*')) {
            return true;
        }
        if ($request->is('area', 'area/*')) {
            $second = (string) $request->segment(2);

            return ! in_array($second, (array) config('i18n.translated_area', []), true);
        }

        return false;
    }

    private function isBackoffice(Request $request): bool
    {
        if ($request->is('admin', 'admin/*', 'admin-files/*')) {
            return true;
        }
        // le chiamate Livewire del back-office partono da una pagina /admin
        if ($request->is('livewire/*')) {
            $ref = (string) parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);

            return $ref === '/admin' || str_starts_with($ref, '/admin/');
        }

        return false;
    }

    private function cookie(string $code): Cookie
    {
        return cookie((string) config('i18n.cookie'), $code, (int) config('i18n.cookie_days', 365) * 1440, '/', null, null, false)->withSameSite('lax');
    }

    private function localizeLinks(Response $response, string $locale): void
    {
        if ($response->isRedirection()) {
            $to = (string) $response->headers->get('Location');
            $host = request()->getSchemeAndHttpHost();
            $path = str_starts_with($to, $host) ? substr($to, strlen($host)) : $to;
            if (str_starts_with($path, '/') && ! str_starts_with($path, '//') && Locales::isPublicPath($path)) {
                $response->headers->set('Location', ($path === $to ? '' : $host).Locales::url($path, $locale));
            }

            return;
        }
        $type = (string) $response->headers->get('Content-Type');
        if (! str_contains($type, 'text/html') || ! method_exists($response, 'getContent')) {
            return;
        }
        $html = $response->getContent();
        if (! is_string($html) || $html === '') {
            return;
        }
        $out = preg_replace_callback('/\b(href|action)=(["\'])(\/(?!\/)[^"\'\s]*)\2/i', function (array $m) use ($locale): string {
            return $m[1].'='.$m[2].Locales::url($m[3], $locale).$m[2];
        }, $html);
        if (is_string($out)) {
            $response->setContent($out);
        }
    }
}
