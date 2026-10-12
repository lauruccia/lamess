<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use App\Models\Period;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ricalcolo notturno del mese aperto: lancia il calcolo «live» (stima) così qualifiche, clienti e «cosa manca»
 * restano aggiornati senza aspettare la chiusura. Con il piano Incaricati riscrive anche le qualifiche ufficiali del mese
 * (raggiunte e, dopo un reso, tolte); premi e righe bloccate restano riservati al runtime ufficiale e alla chiusura.
 *
 * Lavora solo sul primo mese non chiuso (ogni mese parte dallo stato del precedente, che deve essere chiuso),
 * se è già iniziato e se non è fermo in un calcolo ufficiale in corso.
 */
final class NightlyRecalculation
{
    public const CACHE_KEY = 'lamess.nightly_recalc.last';

    public function __construct(private readonly PeriodService $periods) {}

    /** @return array{action:string, period:?string, message:string} */
    public function run(): array
    {
        $period = Period::where('status', '!=', Period::CLOSED)->orderBy('code')->first();
        if ($period === null) {
            return $this->done('none', null, 'Nessun mese da ricalcolare.');
        }
        if ($period->starts_at->gt(now())) {
            return $this->done('none', $period->code, "Il mese {$period->code} non è ancora iniziato.");
        }
        if (! in_array($period->status, [Period::OPEN, Period::REVIEW], true)) {
            return $this->done('skipped', $period->code, "Il mese {$period->code} ha un calcolo in corso ({$period->status}): riprovo la notte dopo.");
        }

        try {
            $run = $this->periods->calculate($period, 'live', $this->writesRanks($period));
        } catch (PeriodStateException $e) {
            return $this->done('skipped', $period->code, $e->getMessage());
        } catch (Throwable $e) {
            return $this->done('failed', $period->code, "Errore nel ricalcolo di {$period->code}: ".$e->getMessage());
        }

        return $this->done('calculated', $period->code, "Mese {$period->code} ricalcolato (calcolo #{$run->id}).");
    }

    /** Le qualifiche ufficiali si riscrivono ogni notte solo per i mesi calcolati con il piano Incaricati. */
    private function writesRanks(Period $period): bool
    {
        return $period->planVersion->plan()->kosmos !== null;
    }

    /** @return array{action:string, period:?string, message:string} */
    private function done(string $action, ?string $code, string $message): array
    {
        Cache::forever(self::CACHE_KEY, ['at' => now()->toIso8601String(), 'action' => $action, 'period' => $code, 'message' => $message]);

        return ['action' => $action, 'period' => $code, 'message' => $message];
    }
}
