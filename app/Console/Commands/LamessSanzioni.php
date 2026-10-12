<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Compliance\ComplianceService;
use Illuminate\Console\Command;

class LamessSanzioni extends Command
{
    protected $signature = 'lamess:sanzioni';

    protected $description = 'Chiude le sanzioni a tempo scadute e ripristina lo stato dell\'incaricato';

    public function handle(ComplianceService $svc): int
    {
        $n = $svc->expireDue();
        $this->info("Sanzioni scadute: {$n}");

        return self::SUCCESS;
    }
}
