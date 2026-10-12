<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\SuperAdminCreator;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class LamessSuperAdmin extends Command
{
    protected $signature = 'lamess:superadmin {email : email di accesso} {--name= : nome e cognome} {--password= : password (se omessa viene chiesta senza mostrarla)} {--consenti-altro : crea un super_admin anche se ne esiste già uno}';

    protected $description = 'Crea il super_admin (accesso totale, invisibile agli altri ruoli). Si può fare solo da qui o dall\'installazione guidata, mai dal pannello';

    public function handle(SuperAdminCreator $creator): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Nome e cognome'));
        $password = (string) $this->option('password');
        if ($password === '') {
            $password = (string) $this->secret('Password (almeno 12 caratteri, lettere e numeri)');
            if ($password !== (string) $this->secret('Ripeti la password')) {
                $this->error('Le due password non coincidono.');

                return self::FAILURE;
            }
        } else {
            $this->warn('Password passata come opzione: resta nella cronologia del terminale. Meglio ometterla e digitarla quando richiesta.');
        }

        try {
            $user = $creator->create($name, (string) $this->argument('email'), $password, (bool) $this->option('consenti-altro'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $m) {
                    $this->error($m);
                }
            }

            return self::FAILURE;
        }
        $this->info("Super_admin {$user->email} creato. Al primo accesso a /admin il pannello chiede di collegare l'app di autenticazione (2FA).");

        return self::SUCCESS;
    }
}
