<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Access\Roles;
use Illuminate\Console\Command;

final class LamessRuoli extends Command
{
    protected $signature = 'lamess:ruoli';

    protected $description = 'Sincronizza ruoli e permessi del back-office con la matrice in App\Domain\Access\Roles';

    public function handle(): int
    {
        Roles::sync();
        $rows = [];
        foreach (Roles::MATRIX as $role => $perms) {
            $rows[] = [$role, implode(', ', $perms)];
        }
        array_unshift($rows, [Roles::SUPER_ADMIN, '(tutti)']);
        $this->table(['Ruolo', 'Permessi'], $rows);

        return self::SUCCESS;
    }
}
