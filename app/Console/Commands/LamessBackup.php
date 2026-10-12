<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\System\BackupService;
use Illuminate\Console\Command;

final class LamessBackup extends Command
{
    protected $signature = 'lamess:backup';

    protected $description = 'Crea una copia di sicurezza del database (le ultime 14 restano in storage/app/backups)';

    public function handle(BackupService $backup): int
    {
        try {
            $r = $backup->create('automatico');
        } catch (\Throwable $e) {
            $this->error('Backup non riuscito: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->info("Backup creato: {$r['name']} (".number_format($r['size'] / 1024, 0, ',', '.').' KB)');

        return self::SUCCESS;
    }
}
