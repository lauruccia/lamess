<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Engine\Support\PeriodKey;
use App\Domain\Periods\PeriodService;
use App\Models\CompPlanVersion;
use App\Models\Period;
use DateTimeInterface;

/** Trova (o apre, se configurato) il periodo che accoglie un movimento PV: mese di conferma del pagamento (OPEN-25). */
final class PeriodResolver
{
    public function __construct(private readonly PeriodService $periods) {}

    public function forDate(DateTimeInterface $at, bool $open = true): ?Period
    {
        $code = PeriodKey::of($at)->code();
        $period = Period::where('code', $code)->first();
        if ($period) {
            if ($period->isClosed()) {
                throw new OrderException("Il periodo {$code} è già chiuso: il movimento non può essere registrato.");
            }

            return $period;
        }
        if (! $open) {
            return null;
        }
        if (! config('lamess.auto_open_period')) {
            throw new OrderException("Il periodo {$code} non è aperto: aprilo dal back-office.");
        }
        $plan = CompPlanVersion::inForceForPeriod($code)
            ?? throw new OrderException('Nessun piano compensi attivo: impossibile aprire il periodo.');

        return $this->periods->open($code, $plan);
    }
}
