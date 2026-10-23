<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Domain\Access\Impersonation;
use App\Models\User;
use Closure;
use Filament\Actions\Action;

/** Azione «Accedi come»: solo super_admin, con conferma; porta nell'area dell'utente scelto. */
final class ImpersonateAction
{
    /** @param  Closure(mixed):?User  $userOf  ricava l'utente dal record della riga */
    public static function forRow(Closure $userOf, bool $iconButton = false): Action
    {
        $action = Action::make('impersonate')->label('Accedi come')->icon('heroicon-o-eye')->color('warning')
            ->visible(function ($record) use ($userOf): bool {
                $target = $userOf($record);

                return $target instanceof User && Impersonation::canImpersonate(auth()->user(), $target) && ! Impersonation::active();
            })
            ->requiresConfirmation()->modalHeading('Accedi come questo utente')
            ->modalDescription('Vedrai il portale esattamente come lo vede lui. Pagamenti, password, firme e dati bancari restano bloccati; ingresso e uscita sono registrati nel log. Con «Torna al mio account» rientri nel tuo.')
            ->action(function ($record) use ($userOf) {
                $target = $userOf($record);
                abort_unless($target instanceof User, 404);
                Impersonation::start(request(), auth()->user(), $target);

                return redirect($target->isBackoffice() ? '/admin' : '/area');
            });

        return $iconButton ? $action->iconButton()->tooltip('Accedi come') : $action;
    }
}
