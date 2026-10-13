<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Orders\KMoneyProvider;
use App\Domain\Orders\KMoneySubscriptionSync;
use App\Models\Subscription;
use Illuminate\Console\Command;

class LamessAbbonamentiKMoney extends Command
{
    protected $signature = 'lamess:abbonamenti-kmoney';

    protected $description = 'Manda a KMoney la copia di tutti gli abbonamenti pagati con KMoney (attivi e in pausa), così il cliente li vede nella sua area KMoney e può bloccarli';

    public function handle(KMoneyProvider $kmoney): int
    {
        if (! $kmoney instanceof KMoneySubscriptionSync || ! $kmoney->enabled()) {
            $this->warn('KMoney non è attivo con il driver «api»: niente da inviare.');

            return self::SUCCESS;
        }
        $sent = 0;
        Subscription::where('payment_method', Subscription::METHOD_KMONEY)
            ->whereIn('status', [Subscription::ACTIVE, Subscription::PAUSED])
            ->orderBy('id')
            ->each(function (Subscription $sub) use ($kmoney, &$sent): void {
                $kmoney->syncSubscription($sub);
                $sent++;
            });
        $this->info("Abbonamenti KMoney inviati: {$sent}.");

        return self::SUCCESS;
    }
}
