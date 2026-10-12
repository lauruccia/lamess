<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Succession\InheritanceService;
use Illuminate\Console\Command;

class LamessSuccessioni extends Command
{
    protected $signature = 'lamess:successioni';

    protected $description = 'Fa cessare i codici di incaricati deceduti rimasti senza decisione oltre il limite';

    public function handle(InheritanceService $svc): int
    {
        $this->info('Pratiche chiuse per decorrenza: '.$svc->endStale());

        return self::SUCCESS;
    }
}
