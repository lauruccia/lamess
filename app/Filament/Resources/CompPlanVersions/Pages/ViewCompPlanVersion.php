<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompPlanVersions\Pages;

use App\Domain\Periods\PeriodService;
use App\Domain\Plan\PlanVersionService;
use App\Filament\Resources\CompPlanVersions\CompPlanVersionResource;
use App\Models\CompPlanVersion;
use App\Models\Period;
use DateTimeImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Validation\ValidationException;

class ViewCompPlanVersion extends ViewRecord
{
    protected static string $resource = CompPlanVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('simulate')->label('Simula impatto')->icon('heroicon-o-beaker')->color('gray')
                ->schema([Select::make('period_id')->label('Periodo su cui simulare')->required()->options(fn () => Period::orderByDesc('code')->pluck('code', 'id'))])
                ->modalDescription('Ricalcola il periodo con questa versione e la confronta con il piano del periodo. Non scrive nulla.')
                ->action(function (array $data, PeriodService $svc): void {
                    $r = $svc->simulate(Period::findOrFail($data['period_id']), $this->record);
                    $lines = collect($r['totals'])->map(fn ($t, $b) => sprintf('%s: %s → %s (%+.2f)', $b, $t['base'], $t['alt'], (float) (string) $t['diff']))->all();
                    Notification::make()->title('Impatto della simulazione')
                        ->body($lines === [] ? 'Nessuna differenza.' : implode("\n", $lines)."\nIncaricati con differenze: ".count($r['by_partner']))
                        ->persistent()->success()->send();
                }),
            Action::make('edit')->authorize('plan.manage')->label('Modifica bozza')->icon('heroicon-o-pencil-square')->color('gray')
                ->visible(fn () => $this->record->status === CompPlanVersion::DRAFT || PlanVersionService::isSuper())
                ->url(fn () => CompPlanVersionResource::getUrl('edit', ['record' => $this->record])),
            Action::make('activate')->authorize('plan.manage')
                ->label(fn () => PlanVersionService::fourEyes() && ! PlanVersionService::isSuper() ? 'Chiedi approvazione' : ($this->record->status === CompPlanVersion::ARCHIVED ? 'Rimetti in vigore' : 'Attiva'))->icon('heroicon-o-check-badge')
                ->visible(fn () => $this->record->status === CompPlanVersion::DRAFT || (PlanVersionService::isSuper() && $this->record->status !== CompPlanVersion::ACTIVE))
                ->schema([
                    DatePicker::make('effective_from')->label('Efficace dal')->required()->default(now()->toDateString())->minDate(fn () => PlanVersionService::isSuper() ? null : now()->toDateString())
                        ->helperText('Vale per i periodi che si aprono da questa data. Oggi = subito; una data futura la programma. I periodi già aperti mantengono il loro piano.'),
                    TextInput::make('reason')->label('Motivo')->required(),
                ])
                ->modalDescription(fn () => PlanVersionService::fourEyes() && ! PlanVersionService::isSuper()
                    ? 'La versione resta in attesa finché un secondo amministratore (diverso da te) la approva. Nel frattempo non si può modificare.'
                    : 'La versione in vigore viene archiviata quando questa diventa efficace.')
                ->action(function (array $data, PlanVersionService $svc): void {
                    $v = $svc->publish($this->record, new DateTimeImmutable($data['effective_from']), $data['reason']);
                    Notification::make()->title(match ($v->status) {
                        CompPlanVersion::ACTIVE => 'Versione attivata',
                        CompPlanVersion::PENDING_APPROVAL => 'Richiesta inviata: attende l\'approvazione di un secondo amministratore',
                        default => 'Versione programmata',
                    })->success()->send();
                }),
            Action::make('approve')->authorize('plan.approve')->label('Approva e pubblica')->icon('heroicon-o-shield-check')->color('success')
                ->visible(fn () => $this->record->status === CompPlanVersion::PENDING_APPROVAL)
                ->schema([TextInput::make('note')->label('Nota (facoltativa)')])
                ->modalDescription(fn () => 'Richiesta di '.($this->record->requested_by_label ?? '—').' — efficace dal '.($this->record->requested_effective_from?->format('d/m/Y') ?? '—').'. Motivo: '.($this->record->request_reason ?? '—'))
                ->action(function (array $data, PlanVersionService $svc): void {
                    try {
                        $v = $svc->approve($this->record, $data['note'] ?? null);
                        Notification::make()->title($v->status === CompPlanVersion::ACTIVE ? 'Versione approvata e attivata' : 'Versione approvata e programmata')->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                    }
                }),
            Action::make('reject')->authorize('plan.approve')->label('Rifiuta')->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn () => $this->record->status === CompPlanVersion::PENDING_APPROVAL)
                ->schema([TextInput::make('reason')->label('Motivo del rifiuto')->required()])
                ->action(function (array $data, PlanVersionService $svc): void {
                    $svc->reject($this->record, $data['reason']);
                    Notification::make()->title('Richiesta rifiutata: la versione è tornata bozza')->success()->send();
                }),
            Action::make('withdraw')->authorize('plan.manage')->label('Ritira la richiesta')->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn () => $this->record->status === CompPlanVersion::PENDING_APPROVAL)->requiresConfirmation()
                ->action(function (PlanVersionService $svc): void {
                    try {
                        $svc->withdraw($this->record);
                        Notification::make()->title('Richiesta ritirata')->success()->send();
                    } catch (ValidationException $e) {
                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                    }
                }),
            Action::make('duplicate')->authorize('plan.manage')->label('Duplica come bozza')->icon('heroicon-o-document-duplicate')->color('gray')
                ->action(function (PlanVersionService $svc) {
                    $copy = $svc->duplicate($this->record);

                    return redirect(CompPlanVersionResource::getUrl('view', ['record' => $copy]));
                }),
        ];
    }
}
