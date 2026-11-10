<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\I18n\Locales;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * «Cambia lingua» per le pagine senza prefisso (area riservata): ricorda la scelta nel cookie e, se si è collegati,
 * sul profilo, così anche email e notifiche arrivano nella lingua scelta.
 */
final class LanguageController extends Controller
{
    public function switch(Request $request, string $code): RedirectResponse
    {
        $user = $request->user();
        $canPreview = $user instanceof User && $user->isBackoffice();
        abort_unless(Locales::isActive($code) || ($canPreview && in_array($code, Locales::allCodes(), true)), 404);

        $to = (string) $request->query('to', '/');
        // solo indirizzi di questo sito: mai «//altro.sito» né un indirizzo completo
        if (! str_starts_with($to, '/') || str_starts_with($to, '//') || str_contains($to, '\\')) {
            $to = '/';
        }

        if ($user instanceof User && Locales::isActive($code) && Schema::hasColumn('users', 'locale')) {
            $user->forceFill(['locale' => $code])->saveQuietly();
        }

        $cookie = cookie((string) config('i18n.cookie'), $code, (int) config('i18n.cookie_days', 365) * 1440, '/', null, null, false)->withSameSite('lax');

        return redirect($to)->withCookie($cookie);
    }
}
