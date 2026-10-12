<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use App\Domain\Audit\AuditLog;
use App\Domain\Engine\Calc\PeriodCalculator;
use App\Models\CalculationRun;
use App\Models\Period;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Chiusura mensile automatica (modalità `lamess.period_auto_close`).
 *
 * Lavora solo sul primo mese non chiuso, solo se è appena finito (entro 45 giorni): i mesi arretrati, ad esempio quelli
 * importati da Odoo, si chiudono a mano uno alla volta perché ciascuno parte dallo stato del precedente.
 *  - calculate: lancia il calcolo ufficiale e lascia il mese «in revisione» per l'admin.
 *  - close: calcola e chiude; se ci sono movimenti in attesa di pagamento si ferma e riprova la notte dopo.
 */
final class PeriodAutomation
{
    public const CACHE_KEY = 'lamess.period_auto.last';

    public function __construct(private readonly PeriodService $periods) {}

    /** @return array{action:string, period:?string, message:string} */
    public function run(?DateTimeInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $mode = (string) config('lamess.period_auto_close');
        if (! in_array($mode, ['calculate', 'close'], true)) {
            return $this->done('off', null, 'Chiusura automatica spenta.');
        }
        $period = Period::where('status', '!=', Period::CLOSED)->orderBy('code')->first();
        if ($period === null || $this->dueAt($period)->gt($now)) {
            return $this->done('none', $period?->code, $period === null ? 'Nessun mese da elaborare.' : "Il calcolo di {$period->code} è fissato per il {$this->dueAt($period)->format('d/m/Y H:i')}.");
        }
        if ($period->ends_at->lt($now->subDays(45))) {
            return $this->done('skipped', $period->code, "Il mese {$period->code} è arretrato: va chiuso a mano, poi l'automatismo riparte dal mese successivo.");
        }
        if ($period->status === Period::REVIEW && $mode === 'calculate') {
            return $this->done('waiting', $period->code, "Il mese {$period->code} è in revisione: aspetta la tua chiusura.");
        }

        try {
            $hasFresh = $period->status === Period::REVIEW && $this->officialIsCurrent($period);
            if (! $hasFresh) {
                $this->periods->calculate($period, 'official');
                AuditLog::record('period.auto_calculated', "Calcolo ufficiale automatico del mese {$period->code}", $period);
            }
            if ($mode === 'calculate') {
                return $this->done('calculated', $period->code, "Calcolo ufficiale del mese {$period->code} fatto: da rivedere e chiudere.");
            }
            $this->periods->close($period->refresh());
            AuditLog::record('period.auto_closed', "Mese {$period->code} chiuso in automatico", $period);

            return $this->done('closed', $period->code, "Mese {$period->code} chiuso in automatico.");
        } catch (PeriodStateException $e) {
            return $this->blocked($period, $e->getMessage());
        } catch (Throwable $e) {
            return $this->blocked($period, 'Errore: '.$e->getMessage());
        }
    }

    /** Data e ora del calcolo mensile: ultimo giorno del mese oppure un giorno del mese successivo, all'ora scelta (ora italiana). */
    public function dueAt(Period $period): CarbonImmutable
    {
        $hour = max(0, min(23, (int) config('lamess.commission_calc_hour', 1)));
        $day = (string) config('lamess.commission_calc_day', '1');
        $monthStart = CarbonImmutable::parse($period->starts_at)->setTimezone('Europe/Rome')->startOfMonth();
        if ($day === 'end') {
            return $monthStart->endOfMonth()->startOfDay()->setTime($hour, 0);
        }
        $n = max(1, min(28, (int) $day));

        return $monthStart->addMonth()->addDays($n - 1)->setTime($hour, 0);
    }

    private function officialIsCurrent(Period $period): bool
    {
        $run = CalculationRun::where('period_id', $period->id)->where('type', 'official')->where('status', 'completed')->latest('id')->first();

        return $run !== null && $run->input_hash === PeriodCalculator::calculate(SnapshotBuilder::build($period))->inputHash;
    }

    /** Un blocco si scrive nel registro una volta sola per motivo, poi si riprova in silenzio. */
    private function blocked(Period $period, string $why): array
    {
        $key = 'lamess.period_auto.blocked.'.$period->code;
        if (Cache::get($key) !== $why) {
            Cache::put($key, $why, now()->addDays(40));
            AuditLog::record('period.auto_blocked', "Chiusura automatica di {$period->code} ferma: {$why}", $period);
        }

        return $this->done('blocked', $period->code, "Mese {$period->code} fermo: {$why}");
    }

    /** @return array{action:string, period:?string, message:string} */
    private function done(string $action, ?string $code, string $message): array
    {
        Cache::forever(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'action' => $action, 'period' => $code, 'message' => $message]);

        return ['action' => $action, 'period' => $code, 'message' => $message];
    }
}
