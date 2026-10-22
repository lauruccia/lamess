<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompPlanVersions\Pages;

use App\Domain\Plan\PlanFormMapper;
use App\Domain\Plan\PlanVersionService;
use App\Filament\Pages\Base\StyledEditPage;
use App\Filament\Resources\CompPlanVersions\CompPlanVersionResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Modifica di una BOZZA del piano. Le versioni attive o archiviate non si modificano. */
class EditCompPlanVersion extends EditRecord
{
    use StyledEditPage;

    protected static string $resource = CompPlanVersionResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return PlanFormMapper::toForm($data['data']) + ['name' => $data['name'], 'notes' => $data['notes'] ?? null];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            $plan = PlanFormMapper::toData($data, $record->data);
            $changes = app(PlanVersionService::class)->updateDraft($record, $data['name'], $plan, $data['notes'] ?? null, $data['reason'] ?? null);
        } catch (\InvalidArgumentException|\OutOfBoundsException $e) {
            throw ValidationException::withMessages(['data.name' => 'Piano non valido: '.$e->getMessage()]);
        }
        if ($changes === []) {
            Notification::make()->title('Nessuna modifica')->warning()->send();
        }

        return $record->refresh();
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('view', ['record' => $this->record]);
    }
}
