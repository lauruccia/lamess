<?php

declare(strict_types=1);

namespace App\Domain\Access;

use Illuminate\Support\Facades\Auth;

/**
 * Regola unica «il super_admin può sempre»: modificare ed eliminare ogni dato del back-office.
 * Le risorse la usano al posto di un `false` fisso; le azioni di modifica/eliminazione diretta
 * (App\Filament\Support\SuperadminActions) compaiono solo per lui. Il log di audit resta fuori: è in sola scrittura.
 */
final class Superadmin
{
    public static function is(): bool
    {
        $user = Auth::user();

        return $user !== null && method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin() === true;
    }
}
