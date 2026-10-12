<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Periods\PeriodService;
use App\Domain\Periods\PeriodStateException;
use App\Models\CompPlanVersion;
use App\Models\Period;
use Illuminate\Console\Command;

/** Ciclo del periodo da riga di comando (in attesa dell'interfaccia admin). */
final class LamessPeriodo extends Command
{
    protected $signature = 'lamess:periodo {azione : apri|live|runtime|chiudi|stato} {codice : YYYY-MM}';

    protected $description = 'Apre, calcola (live/runtime ufficiale) o chiude un periodo commissionale';

    public function handle(PeriodService $svc): int
    {
        $code = (string) $this->argument('codice');
        try {
            switch ($this->argument('azione')) {
                case 'apri':
                    $plan = CompPlanVersion::inForceForPeriod($code) ?? throw new \RuntimeException("Nessuna versione del piano in vigore per {$code}.");
                    $svc->open($code, $plan);
                    $this->info("Periodo {$code} aperto con il piano «{$plan->name}».");
                    break;
                case 'live':
                case 'runtime':
                    $run = $svc->calculate($this->period($code), $this->argument('azione') === 'live' ? 'live' : 'official');
                    $this->info("Calcolo #{$run->id} completato. Totali: ".json_encode($run->totals));
                    break;
                case 'chiudi':
                    $svc->close($this->period($code));
                    $this->info("Periodo {$code} chiuso: righe bloccate.");
                    break;
                case 'stato':
                    $p = $this->period($code);
                    $this->info("{$code}: {$p->status}");
                    break;
                default:
                    $this->error('Azione sconosciuta.');

                    return self::INVALID;
            }
        } catch (PeriodStateException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function period(string $code): Period
    {
        return Period::where('code', $code)->firstOrFail();
    }
}
