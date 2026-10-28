<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

/** Riconoscimento semplice (senza librerie) di bot, dispositivo, browser e sistema dal campo User-Agent. */
final class UserAgent
{
    private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|facebookcatalog|preview|monitor|headless|curl\/|wget|python|php\/|java\/|go-http|axios|libwww|scrapy|lighthouse|pingdom|uptime|embedly|whatsapp\/|telegrambot|bingpreview|adsbot|mediapartners|okhttp|httpclient|postman|node-fetch|undici/i';

    public static function isBot(?string $ua): bool
    {
        $ua = trim((string) $ua);

        return $ua === '' || preg_match(self::BOTS, $ua) === 1;
    }

    public static function device(string $ua): string
    {
        if (preg_match('/iPad|Tablet|PlayBook|Silk/i', $ua) || (stripos($ua, 'Android') !== false && stripos($ua, 'Mobile') === false)) {
            return 'tablet';
        }

        return preg_match('/Mobi|iPhone|iPod|Android/i', $ua) ? 'mobile' : 'desktop';
    }

    public static function browser(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Instagram/i', $ua) => 'Instagram (app)',
            (bool) preg_match('/FBAN|FBAV|FB_IAB/i', $ua) => 'Facebook (app)',
            (bool) preg_match('/TikTok|musical_ly|BytedanceWebview/i', $ua) => 'TikTok (app)',
            (bool) preg_match('/LinkedInApp/i', $ua) => 'LinkedIn (app)',
            (bool) preg_match('/Edg(e|A|iOS)?\//', $ua) => 'Edge',
            (bool) preg_match('/OPR\/|Opera/', $ua) => 'Opera',
            (bool) preg_match('/SamsungBrowser/', $ua) => 'Samsung Internet',
            (bool) preg_match('/Firefox|FxiOS/', $ua) => 'Firefox',
            (bool) preg_match('/Chrome|CriOS/', $ua) => 'Chrome',
            (bool) preg_match('/Safari/', $ua) => 'Safari',
            default => 'Altro',
        };
    }

    public static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/Windows/i', $ua) => 'Windows',
            (bool) preg_match('/iPhone|iPad|iPod|iOS/i', $ua) => 'iOS',
            (bool) preg_match('/Android/i', $ua) => 'Android',
            (bool) preg_match('/Macintosh|Mac OS X/i', $ua) => 'macOS',
            (bool) preg_match('/CrOS/', $ua) => 'ChromeOS',
            (bool) preg_match('/Linux/i', $ua) => 'Linux',
            default => 'Altro',
        };
    }
}
