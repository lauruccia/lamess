<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Audit\AuditLog;
use App\Domain\Features\Feature;
use App\Domain\Features\FeatureRegistry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Gestione degli utenti del back-office da parte del super_admin (pagina Utenti).
 *
 * Regole, tutte verificate dai test:
 *  - il ruolo super_admin NON si assegna e NON si toglie da qui (si crea solo da riga di comando o dall'installazione guidata);
 *  - un super_admin non si modifica da qui (niente blocchi accidentali di chi comanda);
 *  - a un admin si concedono solo i permessi di Roles::grantable(): mai utenti, impostazioni di sistema o log;
 *  - le password vengono generate qui e mostrate UNA volta; nel log non compaiono mai.
 * Le modifiche di ruoli/permessi finiscono nel log di audit anche tramite gli eventi di spatie/permission.
 */
final class UserAdministration
{
    /**
     * Crea un admin con i permessi indicati. Restituisce l'utente e la password temporanea (da consegnare a mano).
     *
     * @param  list<string>  $permissions
     * @return array{user: User, password: string}
     */
    public function createAdmin(string $name, string $email, array $permissions, ?string $password = null): array
    {
        $this->assertGrantable($permissions);
        $password ??= self::generatePassword();
        $this->assertPasswordStrength($password);

        $user = DB::transaction(function () use ($name, $email, $permissions, $password): User {
            if (User::withoutGlobalScopes()->where('email', $email)->exists()) {
                throw ValidationException::withMessages(['email' => 'Esiste già un utente con questa email.']);
            }
            Roles::sync();
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
            $user->assignRole(Roles::ADMIN);
            $user->syncPermissions($permissions);

            return $user;
        });
        AuditLog::record('user.admin_created', "Admin creato: {$user->email}", $user, ['permessi' => array_values($permissions)]);

        return ['user' => $user, 'password' => $password];
    }

    /** @param  list<string>  $permissions */
    public function setPermissions(User $user, array $permissions): User
    {
        $this->assertEditable($user);
        if (! $user->hasRole(Roles::ADMIN)) {
            throw ValidationException::withMessages(['permissions' => 'I permessi singoli si concedono solo agli admin.']);
        }
        $this->assertGrantable($permissions);
        $user->syncPermissions($permissions);

        return $user;
    }

    /**
     * Mostra a un admin SOLO le voci di menu (e i blocchi del cruscotto) indicati; tutte le altre le nasconde e le blocca
     * anche da indirizzo diretto. Si appoggia alle regole «per utente» di Feature, quindi compare in «Funzionalità e viste»
     * e ogni cambio finisce nel log di audit. Si applicano solo le differenze, per non riempire il log.
     *
     * @param  list<string>  $visible  chiavi di FeatureRegistry::menuKeys()
     */
    public function setMenu(User $user, array $visible): User
    {
        $this->assertEditable($user);
        if (! $user->hasRole(Roles::ADMIN)) {
            throw ValidationException::withMessages(['menu' => 'Le voci di menu si scelgono solo per gli admin.']);
        }
        $unknown = array_diff($visible, FeatureRegistry::menuKeys());
        if ($unknown !== []) {
            throw ValidationException::withMessages(['menu' => 'Voci di menu sconosciute: '.implode(', ', $unknown)]);
        }

        $scopeId = (string) $user->getKey();
        foreach (FeatureRegistry::menuKeys() as $key) {
            $rule = null;
            foreach (Feature::rulesFor($key) as $r) {
                if ($r['scope_type'] === 'user' && $r['scope_id'] === $scopeId) {
                    $rule = $r;
                }
            }
            if (in_array($key, $visible, true)) {
                if ($rule !== null) {
                    Feature::reset($key, 'user', $scopeId);
                }
            } elseif ($rule === null || $rule['enabled'] !== false) {
                Feature::set($key, false, 'user', $scopeId);
            }
        }

        return $user;
    }

    /**
     * Voci di menu oggi visibili a un utente, raggruppate come nel form (chiave di gruppo => chiavi).
     *
     * @return array<string, list<string>>
     */
    public function menuState(User $user): array
    {
        $out = [];
        foreach (FeatureRegistry::menuEntries() as $group => $entries) {
            $out[self::menuGroupKey($group)] = array_values(array_filter(array_keys($entries), static fn (string $key): bool => Feature::enabled($key, $user)));
        }

        return $out;
    }

    /** @return list<string> tutte le voci di menu oggi visibili a un utente */
    public function visibleKeys(User $user): array
    {
        return array_values(array_filter(FeatureRegistry::menuKeys(), static fn (string $key): bool => Feature::enabled($key, $user)));
    }

    /** Nome del campo del form per un gruppo di voci di menu. */
    public static function menuGroupKey(string $group): string
    {
        return Str::slug($group);
    }

    /**
     * Dal valore del form (gruppo => chiavi) all'elenco piatto di chiavi.
     *
     * @param  array<string, mixed>|null  $state
     * @return list<string>
     */
    public static function flattenMenu(?array $state): array
    {
        return array_values(array_unique(array_map('strval', array_merge([], ...array_map(static fn ($v): array => array_values((array) $v), array_values($state ?? []))))));
    }

    /** Cambia nome ed email (l'email deve restare unica). */
    public function updateProfile(User $user, string $name, string $email): User
    {
        // nome ed email di un super_admin li cambia solo un super_admin (password, 2FA e ruoli restano fuori da qui)
        if (! Superadmin::is()) {
            $this->assertEditable($user);
        }
        if (User::withoutGlobalScopes()->where('email', $email)->whereKeyNot($user->getKey())->exists()) {
            throw ValidationException::withMessages(['email' => 'Esiste già un utente con questa email.']);
        }
        $user->update(['name' => $name, 'email' => $email]);

        return $user;
    }

    /** Nuova password temporanea, restituita una sola volta. */
    public function resetPassword(User $user): string
    {
        $this->assertEditable($user);
        $password = self::generatePassword();
        $user->update(['password' => $password]);
        DB::table('sessions')->where('user_id', $user->getKey())->delete();   // chiude le sessioni aperte (se il driver è database)
        AuditLog::record('user.password_reset_by_admin', "Password reimpostata dal super_admin: {$user->email}", $user);

        return $password;
    }

    /** Azzera la 2FA (telefono perso): al prossimo accesso il pannello chiede di collegarla di nuovo. */
    public function resetTwoFactor(User $user): void
    {
        $this->assertEditable($user);
        $user->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
        AuditLog::record('user.2fa_reset', "Autenticazione a due fattori azzerata: {$user->email}", $user);
    }

    /** Toglie a un admin ogni accesso al back-office (ruolo e permessi). L'utente non viene cancellato. */
    public function revokeBackoffice(User $user): void
    {
        $this->assertEditable($user);
        $user->syncPermissions([]);
        $user->removeRole(Roles::ADMIN);
        AuditLog::record('user.backoffice_revoked', "Accesso al back-office revocato: {$user->email}", $user);
    }

    public static function generatePassword(): string
    {
        return Str::password(16, letters: true, numbers: true, symbols: false);
    }

    /** @param  list<string>  $permissions */
    private function assertGrantable(array $permissions): void
    {
        $bad = array_diff($permissions, Roles::grantable());
        if ($bad !== []) {
            throw ValidationException::withMessages(['permissions' => 'Permessi non concedibili: '.implode(', ', $bad)]);
        }
    }

    private function assertEditable(User $user): void
    {
        if ($user->isSuperAdmin()) {
            throw ValidationException::withMessages(['user' => 'Il super_admin non si modifica da qui.']);
        }
    }

    private function assertPasswordStrength(string $password): void
    {
        validator(['password' => $password], ['password' => ['required', Password::min(12)]])->validate();
    }
}
