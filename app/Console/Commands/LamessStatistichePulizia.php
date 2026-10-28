<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Analytics\Tracker;
use App\Models\AnalyticsHit;
use App\Models\AnalyticsVisit;
use Illuminate\Console\Command;

class LamessStatistichePulizia extends Command
{
    protected $signature = 'lamess:statistiche-pulizia';

    protected $description = 'Cancella le statistiche del sito più vecchie del periodo di conservazione (LAMESS_ANALYTICS_RETENTION_DAYS, 180 giorni)';

    public function handle(): int
    {
        if (! Tracker::ready()) {
            $this->info('Statistiche: tabelle non ancora create, niente da fare.');

            return self::SUCCESS;
        }
        $days = max(30, (int) config('lamess.analytics.retention_days', 180));
        $limit = now()->subDays($days);
        $hits = AnalyticsHit::where('created_at', '<', $limit)->delete();
        $visits = AnalyticsVisit::where('last_seen_at', '<', $limit)->delete();
        $this->info("Statistiche: cancellate {$visits} visite e {$hits} pagine viste più vecchie di {$days} giorni.");

        return self::SUCCESS;
    }
}
