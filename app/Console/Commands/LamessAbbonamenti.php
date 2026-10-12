<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class LamessAbbonamenti extends Command
{
    protected $signature = 'lamess:abbonamenti';

    protected $description = 'Esegue gli ordini degli abbonamenti in scadenza (addebito con carta) e gestisce tentativi e pause';

    public function handle(SubscriptionService $svc): int
    {
        $r = $svc->runDue();
        $this->info("Abbonamenti: {$r['paid']} ordini pagati, {$r['failed']} addebiti da ripetere, {$r['paused']} messi in pausa.");

        return self::SUCCESS;
    }
}
