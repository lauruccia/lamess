<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Domain\Access\Roles;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Il super_admin è invisibile a chiunque non lo sia: elenchi, ricerche, esportazioni e accesso diretto
 * (route binding → 404) passano tutti dalle query Eloquent di User, quindi basta questo filtro globale.
 *
 * Quando NON si applica:
 *  - nessun utente ancora risolto (login, recupero della sessione, console, code, test senza actingAs):
 *    serve per poter autenticare il super_admin stesso e per i comandi di servizio;
 *  - l'utente collegato è il super_admin.
 *
 * Attenzione: `DB::table('users')` e SQL grezzo NON passano di qui. Nel codice dell'applicazione si usa sempre
 * Eloquent (c'è un test che lo controlla). Per leggere davvero tutti gli utenti: User::withoutGlobalScope(HidesSuperAdmins::class).
 */
final class HidesSuperAdmins implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (! self::actorMustNotSeeSuperAdmins()) {
            return;
        }
        $tables = config('permission.table_names');
        $pivot = $tables['model_has_roles'];
        $roles = $tables['roles'];

        $builder->whereNotExists(function ($q) use ($model, $pivot, $roles): void {
            $q->selectRaw('1')->from($pivot)
                ->join($roles, "{$roles}.id", '=', "{$pivot}.role_id")
                ->whereColumn("{$pivot}.model_id", $model->getQualifiedKeyName())
                ->where("{$pivot}.model_type", $model->getMorphClass())
                ->where("{$roles}.name", Roles::SUPER_ADMIN);
        });
    }

    public static function actorMustNotSeeSuperAdmins(): bool
    {
        $guard = Auth::guard();
        // hasUser() NON risolve l'utente: evita la ricorsione mentre la sessione lo sta ancora caricando
        if (! method_exists($guard, 'hasUser') || ! $guard->hasUser()) {
            return false;
        }
        $actor = $guard->user();

        return $actor instanceof User && ! $actor->isSuperAdmin();
    }
}
