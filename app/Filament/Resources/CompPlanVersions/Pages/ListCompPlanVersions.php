<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompPlanVersions\Pages;

use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Plan\PlanVersionService;
use App\Filament\Resources\CompPlanVersions\CompPlanVersionResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListCompPlanVersions extends SuperadminListRecords
{
    protected static string $resource = CompPlanVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('incaricati')->label('Crea bozza «Piano Incaricati»')->icon('heroicon-o-document-plus')->color('gray')
                ->authorize('plan.manage')->requiresConfirmation()
                ->modalDescription('Crea una BOZZA con i valori delle presentazioni del 08/10/2026. Non attiva nulla: potrai rivedere i valori, simulare e poi pubblicare.')
                ->action(function (): void {
                    $data = json_decode((string) file_get_contents(base_path('config/lamess/'.PlanLoader::INCARICATI_FILE)), true, flags: JSON_THROW_ON_ERROR);
                    $v = app(PlanVersionService::class)->create('Piano Incaricati (bozza 08/10/2026)', $data, 'Da presentazioni «Lamèss Incaricati» e «Seconda parte»; punti aperti da confermare.');
                    Notification::make()->title('Bozza creata')->success()->send();
                    $this->redirect(CompPlanVersionResource::getUrl('view', ['record' => $v]));
                }),
            CreateAction::make()->label('Nuova bozza'),
        ];
    }
}
