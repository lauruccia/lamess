<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Payouts\StructureBonusService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class LamessBonusStruttura extends Command
{
    protected $signature = 'lamess:bonus-struttura {--force : calcola subito, senza guardare giorno e ora}';

    protected $description = 'Calcolo settimanale dei Bonus di Struttura (giorno e ora scelti dall\'admin nelle impostazioni)';

    public function handle(StructureBonusService $svc): int
    {
        $now = CarbonImmutable::now('Europe/Rome');
        if (! $this->option('force')) {
            if (! (bool) config('lamess.structure_bonus_auto')) {
                $this->info('Calcolo settimanale spento.');

                return self::SUCCESS;
            }
            if ($now->dayOfWeekIso !== (int) config('lamess.structure_bonus_weekday') || $now->hour !== (int) config('lamess.structure_bonus_hour')) {
                return self::SUCCESS;
            }
            $slot = 'lamess.structure_bonus.slot.'.$now->format('YmdH');
            if (! Cache::add($slot, 1, now()->addHours(2))) {
                return self::SUCCESS;
            }
        }
        $r = $svc->syncOpenPeriods();
        $this->info("Bonus di Struttura: {$r['new']} nuovi, {$r['updated']} aggiornati.");

        return self::SUCCESS;
    }
}
