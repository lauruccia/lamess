<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Access\Impersonation;
use App\Models\AnalyticsHit;
use App\Models\AnalyticsVisit;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rilevamento delle visite, senza servizi esterni e senza cookie nuovi (si usa la sessione che il sito ha già).
 *
 * Si registrano solo le pagine HTML richieste con GET e risposte con 200; restano fuori bot, richieste interne (Livewire,
 * file statici, webhook), anteprime e l'uso del portale «come» un altro utente. L'IP si conserva solo troncato.
 * Il contrassegno della sessione si scrive durante la richiesta (`stamp`), la scrittura sul database dopo l'invio della
 * risposta (`record`). Qualunque errore qui dentro non deve mai arrivare al visitatore.
 */
final class Tracker
{
    /** Pausa oltre la quale la visita successiva conta come nuova (secondi). */
    public const SESSION_GAP = 1800;

    private const READY_KEY = 'lamess.analytics.ready';

    private const SKIP = ['livewire/*', 'filament/*', 'up', 'css/*', 'js/*', 'img/*', 'fonts/*', 'media/*', 'storage/*', 'webhooks/*', 'installazione', 'admin-files/*', 'favicon.ico', 'robots.txt', 'admin/statistiche-sito'];

    public static function enabled(): bool
    {
        return (bool) config('lamess.analytics.enabled', true);
    }

    /** Le tabelle esistono? (finché la migrazione non è stata eseguita sul sito, il rilevamento resta spento senza errori) */
    public static function ready(): bool
    {
        if (Cache::get(self::READY_KEY) === true) {
            return true;
        }
        $exists = Schema::hasTable('analytics_visits') && Schema::hasTable('analytics_hits');
        $exists ? Cache::forever(self::READY_KEY, true) : Cache::put(self::READY_KEY, false, 600);

        return $exists;
    }

    public static function area(Request $request): string
    {
        return $request->is('admin', 'admin/*') ? 'back' : 'front';
    }

    public function shouldTrack(Request $request, Response $response): bool
    {
        if (! self::enabled() || ! $request->isMethod('GET') || $response->getStatusCode() !== 200 || ! $request->hasSession()) {
            return false;
        }
        if ($request->ajax() || $request->expectsJson() || $request->headers->get('X-Livewire') !== null || in_array(strtolower((string) $request->headers->get('Purpose', $request->headers->get('Sec-Purpose', ''))), ['prefetch', 'prefetch;prerender'], true)) {
            return false;
        }
        if (! str_contains((string) $response->headers->get('Content-Type', ''), 'text/html') || $request->is(...self::SKIP)) {
            return false;
        }
        if (UserAgent::isBot($request->userAgent()) || Impersonation::active($request)) {
            return false;
        }

        return self::ready();
    }

    /** Durante la richiesta: assegna (o rinnova) l'identificativo di visita nella sessione e lo passa a `record`. */
    public function stamp(Request $request): void
    {
        if ($request->attributes->has('analytics')) {
            return;
        }
        $session = $request->session();
        $vid = $session->get('an.vid');
        $last = (int) $session->get('an.last', 0);
        $isNew = ! is_string($vid) || $vid === '' || time() - $last > self::SESSION_GAP;
        if ($isNew) {
            $vid = (string) Str::ulid();
            $session->put('an.vid', $vid);
        }
        $session->put('an.last', time());
        $request->attributes->set('analytics', ['vid' => $vid, 'new' => $isNew]);
    }

    /** Dopo la risposta: scrive visita e pagina vista. */
    public function record(Request $request): void
    {
        $stamp = $request->attributes->get('analytics');
        if (! is_array($stamp)) {
            return;
        }
        $now = now();
        $area = self::area($request);
        $path = self::normalizePath($request->path());
        $user = $request->user();
        $uid = $user instanceof User ? $user->id : null;
        $ua = (string) $request->userAgent();

        $visit = null;
        if (! $stamp['new']) {
            $changes = ['last_seen_at' => $now, 'current_area' => $area, 'current_path' => $path, 'hits' => DB::raw('hits + 1')];
            if ($uid !== null) {
                $changes += ['user_id' => $uid, 'is_staff' => $user->isBackoffice()];
            }
            if (AnalyticsVisit::where('vid', $stamp['vid'])->update($changes) > 0) {
                $visit = AnalyticsVisit::where('vid', $stamp['vid'])->value('id');
            }
        }
        if ($visit === null) {
            $visit = $this->openVisit($request, $stamp['vid'], $now, $area, $path, $uid, $user instanceof User && $user->isBackoffice(), $ua);
        }

        $term = null;
        if ($area === 'front' && $path === '/negozio') {
            $term = mb_strtolower(mb_substr(trim((string) $request->query('q')), 0, 120)) ?: null;
        }
        AnalyticsHit::create([
            'visit_id' => $visit, 'created_at' => $now, 'area' => $area, 'path' => $path, 'user_id' => $uid,
            'search_term' => $term, 'search_results' => $term !== null ? $request->attributes->get('analytics.results') : null,
        ]);
    }

    private function openVisit(Request $request, string $vid, \DateTimeInterface $now, string $area, string $path, ?int $uid, bool $staff, string $ua): int
    {
        $src = TrafficSource::classify($request->headers->get('referer'), $request->query(), $ua, $request->getHost());
        $code = $request->query('ref') ?? $request->cookie((string) config('lamess.referral_cookie_name'));

        $visit = AnalyticsVisit::updateOrCreate(['vid' => $vid], [
            'started_at' => $now, 'last_seen_at' => $now, 'user_id' => $uid, 'is_staff' => $staff,
            'landing_area' => $area, 'landing_path' => $path, 'current_area' => $area, 'current_path' => $path,
            'referrer_host' => $src['referrer_host'], 'source' => $src['source'], 'medium' => $src['medium'], 'campaign' => $src['campaign'],
            'referral_code' => is_string($code) && $code !== '' ? mb_substr($code, 0, 40) : null,
            'country' => self::country($request), 'region' => self::header($request, ['CF-Region', 'X-Region'], 80), 'city' => self::header($request, ['CF-IPCity', 'X-City'], 80),
            'device' => UserAgent::device($ua), 'browser' => UserAgent::browser($ua), 'os' => UserAgent::os($ua),
            'ip_masked' => self::maskIp($request->ip()), 'hits' => 1,
        ]);

        return (int) $visit->id;
    }

    /** I segmenti lunghi e casuali (token di firma, reset password, verifiche) non devono finire nelle statistiche. */
    public static function normalizePath(string $path): string
    {
        $parts = array_map(static fn (string $s): string => preg_match('/^[A-Za-z0-9_-]{20,}$/', $s) === 1 ? '*' : $s, explode('/', trim($path, '/')));

        return mb_substr('/'.implode('/', array_filter($parts, static fn (string $s): bool => $s !== '')), 0, 255);
    }

    public static function maskIp(?string $ip): ?string
    {
        $bin = $ip !== null ? @inet_pton($ip) : false;
        if ($bin === false) {
            return null;
        }
        if (strlen($bin) === 4) {
            return implode('.', array_slice(explode('.', $ip), 0, 3)).'.0';
        }

        return inet_ntop(substr($bin, 0, 6).str_repeat("\0", 10)) ?: null;   // IPv6: si tengono i primi 48 bit
    }

    private static function country(Request $request): ?string
    {
        $c = strtoupper((string) self::header($request, ['CF-IPCountry', 'CloudFront-Viewer-Country', 'X-Vercel-IP-Country', 'X-Country-Code'], 2));

        return preg_match('/^[A-Z]{2}$/', $c) === 1 && ! in_array($c, ['XX', 'T1'], true) ? $c : null;
    }

    /** @param list<string> $names */
    private static function header(Request $request, array $names, int $max): ?string
    {
        foreach ($names as $n) {
            $v = trim((string) $request->headers->get($n));
            if ($v !== '') {
                return mb_substr(rawurldecode($v), 0, $max);
            }
        }

        return null;
    }
}
