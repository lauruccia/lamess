<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\Roles;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class LamessAdminUser extends Command
{
    protected $signature = 'lamess:admin-user {email} {--name=Admin} {--password=} {--role=admin : admin o uno dei ruoli di dettaglio (admin_network, admin_finance, …)} {--preset=completo : profilo di permessi per il ruolo admin}';

    protected $description = 'Crea (o aggiorna) un utente del back-office (NON il super_admin: per quello c\'è lamess:superadmin)';

    public function handle(): int
    {
        if ($this->option('role') === Roles::SUPER_ADMIN) {
            $this->error('Il super_admin si crea solo con: php artisan lamess:superadmin');

            return self::FAILURE;
        }
        if (! in_array($this->option('role'), Roles::backoffice(), true)) {
            $this->error('Ruolo non valido. Ruoli: '.implode(', ', array_diff(Roles::backoffice(), [Roles::SUPER_ADMIN])));

            return self::FAILURE;
        }
        if (User::where('email', $this->argument('email'))->first()?->isSuperAdmin()) {
            $this->error('Questo utente è il super_admin: non si modifica con questo comando.');

            return self::FAILURE;
        }
        $password = (string) ($this->option('password') ?: Str::password(16));
        $user = User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('name'), 'password' => $password],
        );
        Roles::sync();
        $user->syncRoles([(string) $this->option('role')]);
        if ($this->option('role') === Roles::ADMIN) {
            $user->syncPermissions(Roles::preset((string) $this->option('preset')));
        }
        $this->info("Utente {$user->email} pronto con ruolo {$this->option('role')}.");
        if (! $this->option('password')) {
            $this->warn("Password generata: {$password} (cambiala al primo accesso)");
        }

        return self::SUCCESS;
    }
}
