<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Audit\AuditLog;
use App\Models\User;

/** Ogni creazione, modifica sensibile o cancellazione di un utente finisce nel log di audit (mai le password). */
final class UserObserver
{
    public function created(User $user): void
    {
        AuditLog::record('user.created', "Utente creato: {$user->email}", $user, ['name' => $user->name, 'email' => $user->email]);
    }

    public function updated(User $user): void
    {
        $changes = [];
        foreach (['name', 'email'] as $field) {
            if ($user->wasChanged($field)) {
                $changes[$field] = ['prima' => $user->getOriginal($field), 'dopo' => $user->{$field}];
            }
        }
        if ($user->wasChanged('password')) {
            $changes['password'] = 'modificata';
        }
        if ($user->wasChanged('app_authentication_secret')) {
            $had = filled($user->getOriginal('app_authentication_secret'));
            $has = filled($user->app_authentication_secret);
            $changes['autenticazione_a_due_fattori'] = match (true) {
                ! $had && $has => 'attivata',
                $had && ! $has => 'disattivata',
                default => 'rigenerata',
            };
        }
        if ($changes !== []) {
            AuditLog::record('user.updated', "Utente modificato: {$user->email}", $user, $changes);
        }
    }

    public function deleted(User $user): void
    {
        AuditLog::record('user.deleted', "Utente cancellato: {$user->email}", $user, ['email' => $user->email]);
    }
}
