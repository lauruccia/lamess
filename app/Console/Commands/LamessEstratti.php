<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payouts\ReceiptService;
use App\Domain\Payouts\StatementLifecycle;
use Illuminate\Console\Command;

class LamessEstratti extends Command
{
    protected $signature = 'lamess:estratti';

    protected $description = 'Accetta per decorrenza dei termini gli estratti conto senza reclamo entro i giorni previsti (Reg. 5.2)';

    public function handle(StatementLifecycle $svc, ReceiptService $receipts): int
    {
        $this->info("Estratti accettati per decorrenza dei termini: {$svc->acceptDue()}. Ricevute accettate: {$receipts->acceptDue()}.");

        return self::SUCCESS;
    }
}
