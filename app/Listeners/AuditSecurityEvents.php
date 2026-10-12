<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Domain\Audit\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Spatie\Permission\Events\PermissionAttachedEvent;
use Spatie\Permission\Events\PermissionDetachedEvent;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Eventi di sicurezza → log di audit: accessi del back-office, tentativi falliti, blocchi, reset password,
 * assegnazione e rimozione di ruoli e permessi. (Gli accessi riusciti di incaricati e clienti non si registrano:
 * volume alto e dati personali inutili.)
 */
final class AuditSecurityEvents
{
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, $this->login(...));
        $events->listen(Logout::class, $this->logout(...));
        $events->listen(Failed::class, $this->failed(...));
        $events->listen(Lockout::class, $this->lockout(...));
        $events->listen(PasswordReset::class, $this->passwordReset(...));
        $events->listen(RoleAttachedEvent::class, fn (RoleAttachedEvent $e) => $this->roles($e->model, $e->rolesOrIds, 'assegnato'));
        $events->listen(RoleDetachedEvent::class, fn (RoleDetachedEvent $e) => $this->roles($e->model, $e->rolesOrIds, 'rimosso'));
        $events->listen(PermissionAttachedEvent::class, fn (PermissionAttachedEvent $e) => $this->permissions($e->model, $e->permissionsOrIds, 'concesso'));
        $events->listen(PermissionDetachedEvent::class, fn (PermissionDetachedEvent $e) => $this->permissions($e->model, $e->permissionsOrIds, 'revocato'));
    }

    private function login(Login $e): void
    {
        if ($e->user instanceof User && $e->user->isBackoffice()) {
            AuditLog::record('auth.login', "Accesso al back-office: {$e->user->email}", $e->user);
        }
    }

    private function logout(Logout $e): void
    {
        if ($e->user instanceof User && $e->user->isBackoffice()) {
            AuditLog::record('auth.logout', "Uscita dal back-office: {$e->user->email}", $e->user);
        }
    }

    private function failed(Failed $e): void
    {
        $email = mb_substr((string) ($e->credentials['email'] ?? ''), 0, 190);
        AuditLog::record('auth.login_failed', 'Accesso non riuscito'.($email !== '' ? " per {$email}" : ''), $e->user instanceof User ? $e->user : null, ['email' => $email]);
    }

    private function lockout(Lockout $e): void
    {
        AuditLog::record('auth.lockout', 'Troppi tentativi di accesso: blocco temporaneo', null, ['email' => mb_substr((string) $e->request->input('email', ''), 0, 190)]);
    }

    private function passwordReset(PasswordReset $e): void
    {
        if ($e->user instanceof User) {
            AuditLog::record('auth.password_reset', "Password reimpostata: {$e->user->email}", $e->user);
        }
    }

    private function roles(mixed $model, mixed $rolesOrIds, string $verb): void
    {
        if (! $model instanceof User) {
            return;
        }
        $names = Collection::wrap($rolesOrIds)->map(fn ($r) => $r instanceof Role ? $r->name : (is_numeric($r) ? Role::query()->whereKey($r)->value('name') : (string) $r))->filter()->values()->all();
        AuditLog::record('user.role', "Ruolo {$verb} a {$model->email}: ".implode(', ', $names), $model, ['ruoli' => $names, 'operazione' => $verb]);
    }

    private function permissions(mixed $model, mixed $permissionsOrIds, string $verb): void
    {
        if (! $model instanceof User) {
            return;
        }
        $names = Collection::wrap($permissionsOrIds)->map(fn ($p) => $p instanceof Permission ? $p->name : (is_numeric($p) ? Permission::query()->whereKey($p)->value('name') : (string) $p))->filter()->values()->all();
        AuditLog::record('user.permission', "Permessi {$verb} a {$model->email}: ".implode(', ', $names), $model, ['permessi' => $names, 'operazione' => $verb]);
    }
}
