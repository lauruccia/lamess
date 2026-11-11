<?php

declare(strict_types=1);

namespace App\Domain\Features;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Collega le pagine dell'area riservata alle funzionalità «area.menu.*» di FeatureRegistry.
 * Una sola mappa serve sia al menu (cosa mostrare) sia al controllo lato server (cosa aprire).
 */
final class AreaMenu
{
    /** Primo pezzo dell'indirizzo dopo /area => chiave della funzionalità. */
    private const SEGMENTS = [
        'rete' => 'area.menu.rete',
        'clienti' => 'area.menu.clienti',
        'referral' => 'area.menu.referral',
        'compensi' => 'area.menu.compensi',
        'statini' => 'area.menu.statini',
        'statini-bonus' => 'area.menu.statini',
        'wallet' => 'area.menu.wallet',
        'ricevute' => 'area.menu.ricevute',
        'ordini' => 'area.menu.ordini',
        'abbonamenti' => 'area.menu.abbonamenti',
        'novita' => 'area.menu.novita',
        'materiali' => 'area.menu.materiali',
        'eventi' => 'area.menu.eventi',
        'conformita' => 'area.menu.conformita',
        'onboarding' => 'area.menu.onboarding',
        'assistenza' => 'area.menu.assistenza',
        'profilo' => 'area.menu.profilo',
        'cessione' => 'area.menu.cessione',
        'diventa-incaricato' => 'area.menu.diventa',
        'pagina' => 'area.personal_page',
    ];

    /** Chiave per un indirizzo come «/area/ordini/12» o «/piano-compensi»; null se non è una voce spegnibile. */
    public static function keyFor(string $path): ?string
    {
        $path = trim($path, '/');
        if ($path === 'piano-compensi') {
            return 'site.piano_compensi';
        }
        if (! str_starts_with($path, 'area/')) {
            return null;
        }
        $segment = explode('/', $path)[1] ?? '';

        return self::SEGMENTS[$segment] ?? null;
    }

    public static function allows(string $path, ?Authenticatable $user = null): bool
    {
        $key = self::keyFor($path);

        return $key === null || Feature::enabled($key, $user);
    }
}
