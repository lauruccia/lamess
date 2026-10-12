<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\InvoiceService;
use Illuminate\Console\Command;

final class LamessFatture extends Command
{
    protected $signature = 'lamess:fatture';

    protected $description = 'Reinvia al gestionale le fatture e note di credito non consegnate';

    public function handle(InvoiceService $invoices): int
    {
        $n = $invoices->retryPending();
        $this->info("Documenti consegnati: {$n}");

        return self::SUCCESS;
    }
}
