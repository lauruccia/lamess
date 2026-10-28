<?php

declare(strict_types=1);

namespace App\Filament\Resources\AnnualFees;

use App\Domain\Onboarding\FeeService;
use App\Domain\Onboarding\OnboardingException;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\AnnualFees\Pages\EditAnnualFee;
use App\Filament\Resources\AnnualFees\Pages\ListAnnualFees;
use App\Models\AnnualFee;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Canoni annuali (RF-021/022). */
class AnnualFeeResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'fees.view';
    }

    protected static function managePermission(): string
    {
        return 'fees.manage';
    }

    protected static ?string $model = AnnualFee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'canone';

    protected static ?string $pluralModelLabel = 'Canoni annuali';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('due_at')->columns([
            TextColumn::make('consultant.name')->label('Incaricato')->searchable(),
            TextColumn::make('year_index')->label('Anno'),
            TextColumn::make('amount')->label('Importo (€)')->numeric(2),
            TextColumn::make('due_at')->label('Scadenza')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
            TextColumn::make('status')->label('Stato')->badge()->color(fn (string $state) => match ($state) {
                'due' => 'warning', 'paid' => 'success', default => 'gray',
            }),
            TextColumn::make('paid_at')->label('Pagato il')->dateTime('d/m/Y', 'Europe/Rome')->placeholder('—'),
            TextColumn::make('method')->label('Metodo')->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['due' => 'Da pagare', 'paid' => 'Pagati', 'void' => 'Annullati'])->default('due'),
        ])->recordActions([
            Action::make('paid')->authorize('fees.manage')->label('Segna pagato')->iconButton()->tooltip('Segna pagato')->icon('heroicon-o-check')->color('success')
                ->visible(fn (AnnualFee $record) => $record->status === 'due')
                ->schema([Select::make('method')->label('Metodo')->options(['transfer' => 'Bonifico', 'wallet' => 'Wallet'])->required()->default('transfer')])
                ->action(function (AnnualFee $record, array $data): void {
                    try {
                        app(FeeService::class)->markPaid($record, $data['method']);
                        Notification::make()->title('Canone registrato come pagato')->success()->send();
                    } catch (OnboardingException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();
                    }
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAnnualFees::route('/'), 'edit' => EditAnnualFee::route('/{record}/edit')];
    }
}
