<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\InvoiceService;
use Illuminate\Console\Command;

final class LamessFatture extends Command
{
    protected $signature = 'lamess:fatture {--tutte : invia tutte le fatture in coda, anche in modalità «in blocco» (invio mensile)}';

    protected $description = 'Reinvia al gestionale le fatture e note di credito non consegnate; con --tutte fa l\'invio in blocco';

    public function handle(InvoiceService $invoices): int
    {
        if ($this->option('tutte')) {
            if (($why = InvoiceService::sendBlocker()) !== null) {
                $this->warn("Invio in blocco non eseguito: {$why}");

                return self::SUCCESS;
            }
            $r = $invoices->sendPending();
            $this->info("Inviati: {$r['sent']}, non riusciti: {$r['failed']}");

            return self::SUCCESS;
        }

        $n = $invoices->retryPending();
        $this->info("Documenti consegnati: {$n}");

        return self::SUCCESS;
    }
}
