<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceCases\Pages;

use App\Domain\Compliance\ComplianceException;
use App\Domain\Compliance\ComplianceService;
use App\Filament\Resources\ComplianceCases\ComplianceCaseResource;
use App\Models\ComplianceCase;
use App\Models\Consultant;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use App\Filament\Pages\Base\SuperadminListRecords;
use Filament\Schemas\Components\Component;

class ListComplianceCases extends SuperadminListRecords
{
    protected static string $resource = ComplianceCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')->authorize('compliance.manage')->label('Apri un caso')->icon('heroicon-o-plus')
                ->schema(self::openForm())
                ->action(function (array $data): void {
                    try {
                        $case = app(ComplianceService::class)->openCase(Consultant::findOrFail($data['consultant_id']), $data['category'], $data['description']);
                        Notification::make()->title("Caso {$case->number} aperto")->success()->send();
                    } catch (ComplianceException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }

    /** @return list<Component> */
    public static function openForm(): array
    {
        return [
            Select::make('consultant_id')->label('Incaricato')->searchable()->required()
                ->getSearchResultsUsing(fn (string $search) => Consultant::where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))->limit(30)->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->code} — {$c->name}"]))
                ->getOptionLabelUsing(fn ($value) => ($c = Consultant::find($value)) ? "{$c->code} — {$c->name}" : ''),
            Select::make('category')->label('Categoria')->required()->options(ComplianceCase::CATEGORIES),
            Textarea::make('description')->label('Fatti segnalati')->required()->rows(5)->helperText('Almeno 20 caratteri. L’incaricato non vede questo testo finché non c\'è un provvedimento.'),
        ];
    }
}
