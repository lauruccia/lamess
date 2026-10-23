<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Subscriptions\SubscriptionDigest;
use Illuminate\Console\Command;

class LamessAbbonamentiRiepilogo extends Command
{
    protected $signature = 'lamess:abbonamenti-riepilogo';

    protected $description = 'Invia all\'amministrazione il riepilogo giornaliero degli abbonamenti da seguire (solo se c\'è qualcosa)';

    public function handle(SubscriptionDigest $digest): int
    {
        $this->info($digest->send() === 1 ? 'Riepilogo abbonamenti inviato.' : 'Niente da segnalare (o nessun destinatario).');

        return self::SUCCESS;
    }
}
