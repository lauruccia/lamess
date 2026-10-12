<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Periods\NightlyRecalculation;
use Illuminate\Console\Command;

class LamessRicalcoloNotturno extends Command
{
    protected $signature = 'lamess:ricalcolo-notturno';

    protected $description = 'Ricalcolo notturno (live) del mese aperto: aggiorna qualifiche, clienti e «cosa manca» senza scrivere dati ufficiali';

    public function handle(NightlyRecalculation $job): int
    {
        $r = $job->run();
        $this->info($r['message']);

        return $r['action'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
