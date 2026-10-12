<?php

declare(strict_types=1);

namespace App\Filament\Resources\InheritanceCases\Pages;

use App\Domain\Succession\InheritanceService;
use App\Domain\Succession\SuccessionException;
use App\Filament\Resources\InheritanceCases\InheritanceCaseResource;
use App\Models\Consultant;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use App\Filament\Pages\Base\SuperadminListRecords;

class ListInheritanceCases extends SuperadminListRecords
{
    protected static string $resource = InheritanceCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('report')->authorize('network.manage')->label('Segnala un decesso')->icon('heroicon-o-plus')
                ->modalDescription('La Lettera IVD cessa subito, il codice resta sospeso e i pagamenti si bloccano. Poi si raccolgono i documenti e si decide il destino del codice.')
                ->schema([
                    Select::make('consultant_id')->label('Incaricato')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search) => Consultant::where('status', '!=', 'terminated')->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} — {$c->name}"]))
                        ->getOptionLabelUsing(fn ($value) => ($c = Consultant::find($value)) ? "{$c->code} — {$c->name}" : ''),
                    DatePicker::make('death_date')->label('Data del decesso')->required()->maxDate(now()->toDateString()),
                    Textarea::make('note')->label('Come ci è stato comunicato'),
                ])
                ->action(function (array $data): void {
                    try {
                        $case = app(InheritanceService::class)->report(Consultant::findOrFail($data['consultant_id']), CarbonImmutable::parse($data['death_date'], config('app.timezone')), (string) ($data['note'] ?? ''));
                        Notification::make()->title("Pratica {$case->number} aperta")->success()->send();
                    } catch (SuccessionException $e) {
                        Notification::make()->title('Non eseguibile')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
