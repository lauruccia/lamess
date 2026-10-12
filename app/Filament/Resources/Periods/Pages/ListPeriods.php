<?php

declare(strict_types=1);

namespace App\Filament\Resources\Periods\Pages;

use App\Domain\Periods\PeriodService;
use App\Domain\Periods\PeriodStateException;
use App\Filament\Resources\Periods\PeriodResource;
use App\Models\CompPlanVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListPeriods extends SuperadminListRecords
{
    protected static string $resource = PeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->authorize('periods.manage')->label('Apri periodo')->icon('heroicon-o-plus')
                ->schema([TextInput::make('code')->label('Mese (AAAA-MM)')->required()->regex('/^\d{4}-(0[1-9]|1[0-2])$/')->placeholder('2026-10')])
                ->action(function (array $data, PeriodService $svc): void {
                    try {
                        $plan = CompPlanVersion::inForceForPeriod($data['code']) ?? throw new PeriodStateException("Nessuna versione del piano in vigore per {$data['code']}.");
                        $svc->open($data['code'], $plan);
                        Notification::make()->title("Periodo {$data['code']} aperto")->body("Piano: {$plan->name}")->success()->send();
                    } catch (PeriodStateException $e) {
                        Notification::make()->title('Impossibile aprire il periodo')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
