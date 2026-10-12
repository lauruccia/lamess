<?php

declare(strict_types=1);

namespace App\Filament\Resources\PvMovements;

use App\Domain\Periods\PeriodStateException;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\PvMovements\Pages\CreatePvMovement;
use App\Filament\Resources\PvMovements\Pages\ListPvMovements;
use App\Filament\Resources\PvMovements\Pages\EditPvMovement;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\PvMovement;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** PV Ledger: i movimenti non si modificano né si cancellano; si registrano rettifiche o resi (RF-050…053). */
class PvMovementResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'periods.view';
    }

    protected static function managePermission(): string
    {
        return 'periods.manage';
    }

    protected static ?string $model = PvMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'movimento PV';

    protected static ?string $pluralModelLabel = 'Movimenti PV';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period_id')->label('Periodo')->required()->options(fn () => Period::where('status', '!=', 'closed')->orderBy('code')->pluck('code', 'id')),
            Select::make('consultant_id')->label('Incaricato (che genera i punti)')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => Consultant::where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")->limit(30)->pluck('name', 'id'))
                ->getOptionLabelUsing(fn ($value) => Consultant::find($value)?->name),
            Select::make('kind')->label('Tipo punti')->required()->options(['pvf' => 'PVF — acquisto di un cliente', 'pvc' => 'PVC — acquisto dell’incaricato']),
            Select::make('type')->label('Causale')->required()->default('sale')->options(['sale' => 'Vendita', 'adjustment' => 'Rettifica', 'refund' => 'Reso (storno)']),
            TextInput::make('pv')->label('PV (negativo per resi)')->numeric()->required(),
            TextInput::make('cv')->label('CV (vuoto = come PV)')->numeric(),
            TextInput::make('amount')->label('Importo in euro, IVA inclusa (negativo per resi)')->numeric()
                ->helperText('È l’importo che conta nel piano Incaricati. Vuoto = movimento storico: si converte dai PV con «euro per punto» del piano.'),
            DateTimePicker::make('event_at')->label('Data (ora italiana)')->timezone('Europe/Rome')->required()->seconds(false),
            Select::make('reverses_movement_id')->label('Movimento stornato (solo resi)')->searchable()
                ->getSearchResultsUsing(fn (string $search) => PvMovement::where('id', (int) $search)->get()->mapWithKeys(fn ($m) => [$m->id => "#{$m->id} · {$m->pv} PV"]))
                ->getOptionLabelUsing(fn ($value) => ($m = PvMovement::find($value)) ? "#{$m->id} · {$m->pv} PV" : null),
            Select::make('status')->label('Stato')->required()->default('confirmed')->options(['confirmed' => 'Confermato (pagamento incassato)', 'pending_payment' => 'In attesa di pagamento']),
            TextInput::make('external_ref')->label('Riferimento esterno (ordine/fattura)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('period.code')->label('Periodo')->sortable(),
                TextColumn::make('consultant.name')->label('Incaricato')->searchable(),
                TextColumn::make('kind')->label('Tipo')->badge(),
                TextColumn::make('type')->label('Causale'),
                TextColumn::make('pv')->label('PV')->numeric(2),
                TextColumn::make('cv')->label('CV')->numeric(2),
                TextColumn::make('amount')->label('Importo €')->numeric(2)->placeholder('—'),
                TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => match ($state) {
                    'confirmed' => 'success', 'pending_payment' => 'warning', default => 'gray',
                }),
                TextColumn::make('event_at')->label('Data')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
                IconColumn::make('excluded_from_bonus')->label('Escluso')->boolean(),
            ])
            ->filters([
                SelectFilter::make('consultant')->label('Incaricato')->relationship('consultant', 'name')->searchable()->preload(),
                SelectFilter::make('period_id')->label('Periodo')->options(fn () => Period::orderByDesc('code')->pluck('code', 'id')),
                SelectFilter::make('status')->label('Stato')->options(['confirmed' => 'Confermato', 'pending_payment' => 'In attesa', 'cancelled' => 'Annullato']),
                SelectFilter::make('kind')->label('Tipo')->options(['pvf' => 'PVF', 'pvc' => 'PVC']),
            ])
            ->recordActions([
                Action::make('confirm')->authorize('periods.manage')->label('Conferma')->iconButton()->tooltip('Conferma')->icon('heroicon-o-check')->color('success')
                    ->visible(fn (PvMovement $m) => $m->status === 'pending_payment' && ! $m->period->isClosed())
                    ->requiresConfirmation()->action(fn (PvMovement $m) => $m->update(['status' => 'confirmed'])),
                Action::make('cancel')->authorize('periods.manage')->label('Annulla')->iconButton()->tooltip('Annulla')->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (PvMovement $m) => $m->status === 'pending_payment' && ! $m->period->isClosed())
                    ->requiresConfirmation()->action(function (PvMovement $m): void {
                        try {
                            $m->update(['status' => 'cancelled']);
                        } catch (PeriodStateException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPvMovements::route('/'), 'create' => CreatePvMovement::route('/create'), 'edit' => EditPvMovement::route('/{record}/edit')];
    }
}
