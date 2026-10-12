<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Onboarding\FeeService;
use Illuminate\Console\Command;

final class LamessCanoni extends Command
{
    protected $signature = 'lamess:canoni';

    protected $description = 'Canoni annuali: crea le scadenze, invia i promemoria, rinnova o risolve le lettere scadute';

    public function handle(FeeService $fees): int
    {
        $r = $fees->process();
        $this->info("Rinnovi: {$r['renewed']} · scadenze create: {$r['fees_created']} · promemoria: {$r['reminders']} · risoluzioni: {$r['terminated']}");

        return self::SUCCESS;
    }
}
