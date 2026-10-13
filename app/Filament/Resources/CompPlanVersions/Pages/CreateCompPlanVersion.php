<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompPlanVersions\Pages;

use App\Domain\Plan\LegacyPlan;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Plan\PlanFormMapper;
use App\Domain\Plan\PlanVersionService;
use App\Filament\Resources\CompPlanVersions\CompPlanVersionResource;
use App\Models\CompPlanVersion;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateCompPlanVersion extends CreateRecord
{
    protected static string $resource = CompPlanVersionResource::class;

    /** La nuova bozza parte dalla versione in vigore (o, in sua assenza, dal piano di pre-lancio). */
    public function mount(): void
    {
        parent::mount();
        $this->form->fill(PlanFormMapper::toForm($this->baseData()) + ['name' => '', 'notes' => '']);
    }

    /** @return array<string, mixed> */
    private function baseData(): array
    {
        $inForce = CompPlanVersion::inForce()?->data;
        if ($inForce !== null && (! LegacyPlan::hidden() || ($inForce['settings']['engine'] ?? null) === 'kosmos_v1')) {
            return $inForce;
        }
        $file = LegacyPlan::hidden() ? PlanLoader::INCARICATI_FILE : 'plan-prelaunch-2026-07-07.json';

        return json_decode((string) file_get_contents(config_path('lamess/'.$file)), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function handleRecordCreation(array $data): Model
    {
        try {
            $plan = PlanFormMapper::toData($data, $this->baseData());

            return app(PlanVersionService::class)->create($data['name'], $plan, $data['notes'] ?? null);
        } catch (\InvalidArgumentException|\OutOfBoundsException $e) {
            throw ValidationException::withMessages(['data.name' => 'Piano non valido: '.$e->getMessage()]);
        }
    }
}
