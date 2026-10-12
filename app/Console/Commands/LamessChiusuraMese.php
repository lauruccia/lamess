<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Periods\PeriodAutomation;
use Illuminate\Console\Command;

class LamessChiusuraMese extends Command
{
    protected $signature = 'lamess:chiusura-mese';

    protected $description = 'Chiusura mensile automatica: calcolo ufficiale del mese appena finito e, se scelto nelle impostazioni, chiusura';

    public function handle(PeriodAutomation $auto): int
    {
        $r = $auto->run();
        $this->info($r['message']);

        return $r['action'] === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
