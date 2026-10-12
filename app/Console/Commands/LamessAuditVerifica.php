<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use Illuminate\Console\Command;

final class LamessAuditVerifica extends Command
{
    protected $signature = 'lamess:audit-verifica {--ancora= : ancora annotata in precedenza (numero:hash) da confrontare}';

    protected $description = 'Verifica la catena di hash del log di audit (esce con errore se è stata alterata)';

    public function handle(): int
    {
        $r = AuditLog::verify();
        $this->line("Righe controllate: {$r->checked} su {$r->total}");
        $this->line('Protezione nel database: '.($r->dbProtected ? 'attiva' : 'NON attiva (restano catena di hash e blocco nel codice)'));
        if ($r->anchor() !== null) {
            $this->line("Ancora attuale: {$r->anchor()}");
        }
        $problem = $r->problem;
        if ($problem === null && $this->option('ancora')) {
            $problem = AuditLog::checkAnchor((string) $this->option('ancora'));
        }
        if ($problem !== null) {
            $this->error('CATENA COMPROMESSA — '.$problem);
            AuditLog::record('audit.verified', 'Verifica del log da riga di comando: catena COMPROMESSA', ['AuditLog', 'catena'], ['problema' => $problem]);

            return self::FAILURE;
        }
        $this->info('Catena integra.');
        AuditLog::record('audit.verified', 'Verifica del log da riga di comando: catena integra', ['AuditLog', 'catena'], ['righe_controllate' => $r->checked]);

        return self::SUCCESS;
    }
}
