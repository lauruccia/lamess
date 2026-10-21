<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use App\Domain\Periods\PeriodStateException;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\PvMovement;
use DateTimeInterface;
use InvalidArgumentException;

/** Unico punto di scrittura dei movimenti PV (RF-050…053). */
final class PvLedger
{
    /**
     * @param  string  $kind  pvf (clienti) | pvc (acquisti degli incaricati)
     * @param  string|null  $cv  default = pv (OPEN-19)
     * @param  string|null  $amount  importo in euro IVA inclusa (piano Incaricati); vuoto = non noto
     * @param  string|null  $note  causale (assegnazione libera di punti)
     * @param  int|null  $clientUserId  cliente di un movimento senza ordine (assegnazione libera di punti)
     */
    public static function record(
        Period $period,
        Consultant $consultant,
        string $kind,
        string $pv,
        DateTimeInterface $at,
        ?string $cv = null,
        string $status = 'confirmed',
        string $type = 'sale',
        ?PvMovement $reverses = null,
        ?string $externalRef = null,
        bool $excluded = false,
        ?string $amount = null,
        ?string $vapAmount = null,
        ?int $validityMonths = null,
        ?int $clientCount = null,
        bool $makesClient = false,
        ?string $note = null,
        ?int $clientUserId = null,
    ): PvMovement {
        if ($period->isClosed()) {
            throw new PeriodStateException("Il periodo {$period->code} è chiuso: registrare il movimento nel periodo aperto (rettifica).");
        }
        if (! in_array($kind, ['pvf', 'pvc'], true)) {
            throw new InvalidArgumentException("Tipo punti non valido: {$kind}");
        }
        if (! PeriodKey::parse($period->code)->contains($at)) {
            throw new InvalidArgumentException("La data {$at->format('Y-m-d H:i')} non appartiene al periodo {$period->code}");
        }
        if ($reverses !== null && ($type !== 'refund' || Num::of($pv)->isPositive())) {
            throw new InvalidArgumentException('Uno storno deve essere di tipo refund con punti negativi');
        }

        return PvMovement::create([
            'period_id' => $period->id, 'consultant_id' => $consultant->id, 'kind' => $kind, 'type' => $type,
            'status' => $status, 'event_at' => $at, 'pv' => (string) Num::points($pv),
            'cv' => (string) Num::points($cv ?? $pv), 'amount' => $amount === null ? null : (string) Num::money($amount), 'reverses_movement_id' => $reverses?->id,
            'excluded_from_bonus' => $excluded, 'external_ref' => $externalRef,
            'vap_amount' => $vapAmount === null ? null : (string) Num::money($vapAmount), 'validity_months' => $validityMonths, 'client_count' => $clientCount, 'makes_client' => $makesClient,
            'note' => $note, 'client_user_id' => $clientUserId,
        ]);
    }
}
