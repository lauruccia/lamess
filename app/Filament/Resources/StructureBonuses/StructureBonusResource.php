<?php

declare(strict_types=1);

namespace App\Filament\Resources\StructureBonuses;

use App\Domain\Access\Superadmin;
use App\Domain\Payouts\StructureBonusService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\StructureBonuses\Pages\ListStructureBonuses;
use App\Filament\Resources\StructureBonuses\Pages\EditStructureBonus;
use App\Models\StructureBonus;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/** Bonus di Struttura: calcolati ogni settimana, confermati dall'admin e pagati una sola volta. */
class StructureBonusResource extends Resource
{
    use GatedByPermission;

    public const STATUS = [
        StructureBonus::CALCULATED => 'Calcolato', StructureBonus::CONFIRMED => 'Confermato',
        StructureBonus::PAID => 'Pagato', StructureBonus::CANCELLED => 'Annullato',
    ];

    protected static ?string $model = StructureBonus::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'Bonus di Struttura';

    protected static ?string $pluralModelLabel = 'Bonus di Struttura';

    protected static ?string $slug = 'bonus-struttura';

    protected static ?int $navigationSort = 4;

    protected static function viewPermission(): string
    {
        return 'payouts.view';
    }

    protected static function managePermission(): string
    {
        return 'payouts.manage';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return Superadmin::is();
    }

    public static function canDelete($record): bool
    {
        return Superadmin::is();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['beneficiary', 'source', 'period', 'confirmer', 'payer']);
    }

    public static function table(Table $table): Table
    {
        $can = static fn (): bool => (bool) auth()->user()?->can('payouts.manage');

        return $table->defaultSort('id', 'desc')->striped()->paginated([25, 50, 100])->defaultPaginationPageOption(50)
            ->columns([
                TextColumn::make('calculated_at')->label('Calcolato il')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->sortable(),
                TextColumn::make('beneficiary.code')->label('Codice')->searchable(),
                TextColumn::make('beneficiary.name')->label('Incaricato')->searchable(),
                TextColumn::make('source.name')->label('Per la nuova PearlQ')->searchable(),
                TextColumn::make('amount')->label('Importo')->numeric(2)->suffix(' €')->alignEnd()->sortable(),
                TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn ($state, $record) => ($record->engine_status === 'held' && $state === StructureBonus::CALCULATED) ? 'Trattenuto' : (self::STATUS[$state] ?? $state))
                    ->color(fn ($state) => match ($state) {
                        StructureBonus::PAID => 'success', StructureBonus::CONFIRMED => 'info', StructureBonus::CANCELLED => 'gray', default => 'warning',
                    }),
                TextColumn::make('confirmed_at')->label('Confermato il')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->placeholder('—'),
                TextColumn::make('confirmer.name')->label('Confermato da')->placeholder('—'),
                TextColumn::make('paid_at')->label('Pagato il')->dateTime('d/m/Y H:i')->timezone('Europe/Rome')->placeholder('—'),
                TextColumn::make('payment_reference')->label('Riferimento')->placeholder('—')->toggleable(),
                TextColumn::make('note')->label('Nota')->wrap()->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('Stato')->options(self::STATUS),
            ])
            ->toolbarActions([
                BulkAction::make('confirm')->label('Conferma')->icon(Heroicon::OutlinedCheckBadge)->color('info')->visible($can)->requiresConfirmation()
                    ->modalDescription('Dopo la conferma l’importo non cambia più. Registro chi e quando.')
                    ->action(function (Collection $records): void {
                        $n = app(StructureBonusService::class)->confirm($records->pluck('id')->all());
                        Notification::make()->title("{$n} bonus confermati")->success()->send();
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('paid')->label('Segna come pagati')->icon(Heroicon::OutlinedBanknotes)->color('success')->visible($can)
                    ->schema([TextInput::make('reference')->label('Riferimento del bonifico')->required()->maxLength(120)])
                    ->action(function (Collection $records, array $data): void {
                        $r = app(StructureBonusService::class)->markPaid($records->pluck('id')->all(), (string) $data['reference']);
                        $skipped = collect($r['skipped'])->map(fn ($why, $code) => $code.': '.implode(', ', $why))->implode(' · ');
                        Notification::make()->title("{$r['paid']} bonus pagati in {$r['payouts']} pagamenti")
                            ->body($skipped === '' ? null : "Non pagati (restano confermati): {$skipped}")->{$skipped === '' ? 'success' : 'warning'}()->send();
                    })->deselectRecordsAfterCompletion(),
                BulkAction::make('cancel')->label('Annulla')->icon(Heroicon::OutlinedXCircle)->color('danger')->visible($can)
                    ->schema([Textarea::make('reason')->label('Motivo')->required()->maxLength(200)])
                    ->action(function (Collection $records, array $data): void {
                        $n = app(StructureBonusService::class)->cancel($records->pluck('id')->all(), (string) $data['reason']);
                        Notification::make()->title("{$n} bonus annullati")->success()->send();
                    })->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListStructureBonuses::route('/'), 'edit' => EditStructureBonus::route('/{record}/edit')];
    }
}
