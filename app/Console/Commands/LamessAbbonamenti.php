<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Subscriptions\SubscriptionService;
use Illuminate\Console\Command;

class LamessAbbonamenti extends Command
{
    protected $signature = 'lamess:abbonamenti';

    protected $description = 'Invia i promemoria pre-addebito, esegue gli ordini degli abbonamenti in scadenza e gestisce tentativi e pause';

    public function handle(SubscriptionService $svc): int
    {
        $reminders = $svc->sendReminders();
        $r = $svc->runDue();
        $cards = $svc->sendCardExpiryNotices();
        $this->info("Abbonamenti: {$r['paid']} ordini pagati, {$r['failed']} addebiti da ripetere, {$r['paused']} messi in pausa, {$reminders} promemoria inviati, {$cards} avvisi di carta in scadenza.");

        return self::SUCCESS;
    }
}
