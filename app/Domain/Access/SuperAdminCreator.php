<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLog;
use App\Models\Scopes\HidesSuperAdmins;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Crea il super_admin. È l'UNICO punto in cui nasce un super_admin e viene richiamato soltanto da
 * `php artisan lamess:superadmin` e dall'installazione guidata iniziale (protetta da token e a uso singolo):
 * mai dal pannello di amministrazione.
 */
final class SuperAdminCreator
{
    public function exists(): bool
    {
        return User::withoutGlobalScope(HidesSuperAdmins::class)->role(Roles::SUPER_ADMIN)->exists();
    }

    /**
     * @throws RuntimeException se esiste già un super_admin e non si è chiesto esplicitamente un altro
     * @throws ValidationException per email o password non valide
     */
    public function create(string $name, string $email, string $password, bool $allowAnother = false, string $origin = 'riga di comando'): User
    {
        if (! $allowAnother && $this->exists()) {
            throw new RuntimeException('Esiste già un super_admin. Il super_admin è uno solo: per aggiungerne un altro serve l\'opzione esplicita --consenti-altro.');
        }
        validator(
            ['name' => $name, 'email' => $email, 'password' => $password],
            ['name' => ['required', 'string', 'max:190'], 'email' => ['required', 'email', 'max:190'], 'password' => ['required', Password::min(12)->letters()->numbers()]],
            ['password.min' => 'La password deve avere almeno 12 caratteri.'],
        )->validate();

        $user = DB::transaction(function () use ($name, $email, $password): User {
            if (User::withoutGlobalScope(HidesSuperAdmins::class)->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Esiste già un utente con questa email.']);
            }
            Roles::sync();
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(Roles::SUPER_ADMIN);

            return $user;
        });
        AuditLog::record('superadmin.created', "Super_admin creato da {$origin}: {$user->email}", $user, ['origine' => $origin]);

        return $user;
    }
}
