<?php

declare(strict_types=1);

namespace App\Filament\Resources\PvMovements\Pages;

use App\Domain\Ledger\PvLedger;
use App\Domain\Periods\LiveRecalcDispatcher;
use App\Domain\Periods\PeriodStateException;
use App\Filament\Resources\PvMovements\PvMovementResource;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\PvMovement;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class CreatePvMovement extends CreateRecord
{
    protected static string $resource = PvMovementResource::class;

    protected function afterCreate(): void
    {
        LiveRecalcDispatcher::afterResponse();   // il movimento inserito a mano entra subito nei compensi provvisori
    }

    /** Tutta la validazione passa da PvLedger (periodo aperto, data nel periodo, resi negativi). */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return PvLedger::record(
                period: Period::findOrFail($data['period_id']), consultant: Consultant::findOrFail($data['consultant_id']),
                kind: $data['kind'], pv: (string) $data['pv'], at: new \DateTimeImmutable((string) $data['event_at'], new \DateTimeZone('UTC')),
                cv: ($data['cv'] ?? '') === '' ? null : (string) $data['cv'], status: $data['status'], type: $data['type'],
                reverses: ! empty($data['reverses_movement_id']) ? PvMovement::findOrFail($data['reverses_movement_id']) : null,
                externalRef: $data['external_ref'] ?? null, amount: ($data['amount'] ?? '') === '' ? null : (string) $data['amount'],
            );
        } catch (PeriodStateException|InvalidArgumentException $e) {
            Notification::make()->title('Movimento non registrato')->body($e->getMessage())->danger()->send();
            throw new Halt;
        }
    }
}
