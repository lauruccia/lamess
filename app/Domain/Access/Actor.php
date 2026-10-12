<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Come si chiama chi ha fatto un'operazione quando il nome finisce in dati che gli altri amministratori leggono
 * (approvato da, verificato da, cambiato da…). L'identità del super_admin non deve mai comparire lì: al suo posto
 * si scrive «Amministrazione». L'identità vera sta solo nel log di audit, visibile solo a lui.
 */
final class Actor
{
    public const NEUTRAL = 'Amministrazione';

    /** Etichetta pubblica dell'utente (default: quello collegato). */
    public static function label(?Authenticatable $user = null): string
    {
        $user ??= Auth::user();
        if (! $user instanceof User) {
            return 'sistema';
        }

        return $user->isSuperAdmin() ? self::NEUTRAL : (string) $user->email;
    }
}
