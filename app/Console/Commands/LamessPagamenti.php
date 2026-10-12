<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payouts\PayoutService;
use Illuminate\Console\Command;

class LamessPagamenti extends Command
{
    protected $signature = 'lamess:pagamenti';

    protected $description = 'Prepara i pagamenti «da pagare» dei mesi chiusi, dal giorno di pagamento previsto dal piano (default il 15)';

    public function handle(PayoutService $svc): int
    {
        $r = $svc->prepareDue();
        $this->info("Pagamenti: {$r['periods']} mesi elaborati, {$r['created']} pagamenti preparati per {$r['amount']} €.");

        return self::SUCCESS;
    }
}
