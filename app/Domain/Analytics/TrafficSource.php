<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** Da dove arriva una visita: sito di provenienza, parametri UTM, identificativi dei click (fbclid, gclid…) e app di origine. */
final class TrafficSource
{
    /** @var array<string, array{0: string, 1: string}> frammento dell'host => [nome, mezzo] */
    private const HOSTS = [
        'google.' => ['Google', 'organico'], 'bing.com' => ['Bing', 'organico'], 'duckduckgo.com' => ['DuckDuckGo', 'organico'],
        'ecosia.org' => ['Ecosia', 'organico'], 'yahoo.' => ['Yahoo', 'organico'], 'virgilio.it' => ['Virgilio', 'organico'], 'libero.it' => ['Libero', 'organico'],
        'facebook.com' => ['Facebook', 'social'], 'fb.com' => ['Facebook', 'social'], 'fb.me' => ['Facebook', 'social'],
        'instagram.com' => ['Instagram', 'social'], 'tiktok.com' => ['TikTok', 'social'], 'linkedin.com' => ['LinkedIn', 'social'], 'lnkd.in' => ['LinkedIn', 'social'],
        't.co' => ['X (Twitter)', 'social'], 'twitter.com' => ['X (Twitter)', 'social'], 'x.com' => ['X (Twitter)', 'social'],
        'youtube.com' => ['YouTube', 'social'], 'youtu.be' => ['YouTube', 'social'], 'pinterest.' => ['Pinterest', 'social'],
        'whatsapp.com' => ['WhatsApp', 'social'], 'wa.me' => ['WhatsApp', 'social'], 't.me' => ['Telegram', 'social'], 'telegram.org' => ['Telegram', 'social'],
        'mail.google.com' => ['Email', 'email'], 'outlook.' => ['Email', 'email'], 'mail.yahoo.' => ['Email', 'email'],
    ];

    /** @var array<string, string> nome UTM (minuscolo) => nome mostrato */
    private const UTM_NAMES = [
        'google' => 'Google', 'facebook' => 'Facebook', 'fb' => 'Facebook', 'instagram' => 'Instagram', 'ig' => 'Instagram', 'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'newsletter' => 'Email', 'email' => 'Email', 'twitter' => 'X (Twitter)', 'x' => 'X (Twitter)',
    ];

    /**
     * @param  array<string, mixed>  $query  parametri dell'indirizzo
     * @return array{source: string, medium: string, campaign: ?string, referrer_host: ?string}
     */
    public static function classify(?string $referrer, array $query, string $userAgent, string $ownHost): array
    {
        $host = self::host($referrer);
        if ($host !== null && self::sameSite($host, $ownHost)) {
            $host = null;
        }
        $campaign = self::clean($query['utm_campaign'] ?? null, 120);
        $utmSource = mb_strtolower((string) ($query['utm_source'] ?? ''));
        $utmMedium = mb_strtolower((string) ($query['utm_medium'] ?? ''));

        if ($utmSource !== '') {
            $name = self::UTM_NAMES[$utmSource] ?? mb_substr(ucfirst($utmSource), 0, 40);

            return ['source' => $name, 'medium' => self::utmMedium($utmMedium, $name), 'campaign' => $campaign, 'referrer_host' => $host];
        }
        foreach (['gclid' => ['Google', 'a pagamento'], 'fbclid' => ['Facebook', 'social'], 'ttclid' => ['TikTok', 'a pagamento'], 'igshid' => ['Instagram', 'social'], 'igsh' => ['Instagram', 'social']] as $param => [$name, $medium]) {
            if (! empty($query[$param])) {
                return ['source' => $name, 'medium' => $medium, 'campaign' => $campaign, 'referrer_host' => $host];
            }
        }
        if ($host !== null) {
            foreach (self::HOSTS as $needle => [$name, $medium]) {
                if (str_contains($host, $needle)) {
                    return ['source' => $name, 'medium' => $medium, 'campaign' => $campaign, 'referrer_host' => $host];
                }
            }

            return ['source' => 'Altri siti', 'medium' => 'referral', 'campaign' => $campaign, 'referrer_host' => $host];
        }
        // le app dei social aprono i link senza indicare il sito di provenienza: lo si capisce dall'app
        foreach (['Instagram' => '/Instagram/i', 'Facebook' => '/FBAN|FBAV|FB_IAB/i', 'TikTok' => '/TikTok|musical_ly|BytedanceWebview/i', 'LinkedIn' => '/LinkedInApp/i'] as $name => $re) {
            if (preg_match($re, $userAgent)) {
                return ['source' => $name, 'medium' => 'social', 'campaign' => $campaign, 'referrer_host' => null];
            }
        }

        return ['source' => 'Diretto', 'medium' => 'diretto', 'campaign' => $campaign, 'referrer_host' => null];
    }

    private static function utmMedium(string $utmMedium, string $name): string
    {
        return match (true) {
            in_array($utmMedium, ['cpc', 'ppc', 'paid', 'paidsocial', 'paid_social', 'display', 'ads'], true) => 'a pagamento',
            in_array($utmMedium, ['email', 'newsletter', 'mail'], true) || $name === 'Email' => 'email',
            in_array($utmMedium, ['social', 'social-media', 'sm'], true) => 'social',
            $utmMedium === 'organic' => 'organico',
            default => 'referral',
        };
    }

    public static function host(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return mb_substr(preg_replace('/^(www|m|l|lm|mobile)\./', '', mb_strtolower($host)) ?? '', 0, 120) ?: null;
    }

    private static function sameSite(string $host, string $ownHost): bool
    {
        $own = preg_replace('/^www\./', '', mb_strtolower($ownHost)) ?? '';

        return $host === $own || str_ends_with($host, '.'.$own);
    }

    private static function clean(mixed $v, int $max): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
