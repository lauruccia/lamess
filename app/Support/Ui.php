<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/** Piccoli aiuti di presentazione per l'area utente: stati con colore, date all'italiana. */
final class Ui
{
    private const MONTHS = ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

    /** @return array{0: string, 1: string} etichetta e tono (ok, warn, err, info, mute) */
    public static function orderStatus(?string $status): array
    {
        return match ($status) {
            'pending_payment' => ['In attesa di pagamento', 'warn'],
            'paid' => ['Pagato', 'ok'],
            'processing' => ['In preparazione', 'ok'],
            'shipped' => ['Spedito', 'ok'],
            'delivered' => ['Consegnato', 'ok'],
            'cancelled' => ['Annullato', 'mute'],
            'partially_refunded' => ['Reso parziale', 'info'],
            'refunded' => ['Reso', 'info'],
            default => [(string) $status, 'mute'],
        };
    }

    /** @return array{0: string, 1: string} */
    public static function subscriptionStatus(?string $status): array
    {
        return match ($status) {
            'pending' => ['In attesa del primo pagamento', 'warn'],
            'active' => ['Attivo', 'ok'],
            'paused' => ['In pausa', 'warn'],
            'cancelled' => ['Annullato', 'mute'],
            default => [(string) $status, 'mute'],
        };
    }

    /** @return array{0: string, 1: string} */
    public static function ticketStatus(?string $status): array
    {
        return match ($status) {
            'open' => ['In attesa di risposta', 'info'],
            'answered' => ['Hai una risposta', 'ok'],
            'closed' => ['Chiusa', 'mute'],
            default => [(string) $status, 'mute'],
        };
    }

    public static function date(?CarbonInterface $d, string $format = 'd/m/Y'): string
    {
        return $d === null ? '—' : $d->copy()->setTimezone('Europe/Rome')->translatedFormat($format);
    }

    /** Data in forma breve per i riquadri calendario: giorno e mese. @return array{0: string, 1: string} */
    public static function chip(CarbonInterface $d): array
    {
        $local = $d->copy()->setTimezone('Europe/Rome');

        return [$local->format('j'), self::MONTHS[(int) $local->format('n') - 1]];
    }

    /** «tra 3 giorni», «oggi», «domani»: per le scadenze. */
    public static function inDays(?CarbonInterface $d): string
    {
        if ($d === null) {
            return '';
        }
        $days = (int) now('Europe/Rome')->startOfDay()->diffInDays($d->copy()->setTimezone('Europe/Rome')->startOfDay(), false);

        return match (true) {
            $days === 0 => 'oggi',
            $days === 1 => 'domani',
            $days > 1 => "tra {$days} giorni",
            $days === -1 => 'ieri',
            default => abs($days).' giorni fa',
        };
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '', 0, 1);
        $last = count($parts) > 1 ? mb_substr((string) end($parts), 0, 1) : '';

        return mb_strtoupper($first.$last);
    }
}
