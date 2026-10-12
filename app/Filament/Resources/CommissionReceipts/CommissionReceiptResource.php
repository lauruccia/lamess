<?php

declare(strict_types=1);

namespace App\Filament\Resources\CommissionReceipts;

use App\Domain\Access\Superadmin;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\CommissionReceipts\Pages\ListCommissionReceipts;
use App\Filament\Resources\CommissionReceipts\Pages\EditCommissionReceipt;
use App\Models\CommissionReceipt;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Ricevute provvigionali emesse per conto degli incaricati (sola lettura). */
class CommissionReceiptResource extends Resource
{
    use GatedByPermission;

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

    protected static ?string $model = CommissionReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'ricevuta provvigionale';

    protected static ?string $pluralModelLabel = 'Ricevute provvigionali';

    protected static ?int $navigationSort = 7;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Numero')->searchable(),
            TextColumn::make('consultant.code')->label('Codice')->searchable(),
            TextColumn::make('consultant.name')->label('Incaricato')->searchable(),
            TextColumn::make('regime')->label('Regime')->formatStateUsing(fn (string $state) => ['occasional' => 'Con ritenuta', 'vat_holder' => 'Partita IVA'][$state] ?? $state),
            TextColumn::make('gross')->label('Lordo €')->numeric(2),
            TextColumn::make('withholding')->label('Ritenuta €')->numeric(2),
            TextColumn::make('inps_worker')->label('INPS €')->numeric(2)->toggleable(),
            TextColumn::make('net')->label('Netto €')->numeric(2),
            TextColumn::make('status')->label('Stato')->badge(),
            TextColumn::make('issued_at')->label('Emessa il')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['published' => 'Pubblicata', 'disputed' => 'Contestata', 'accepted' => 'Accettata', 'resolved' => 'Decisa']),
        ])->recordActions([
            Action::make('pdf')->label('PDF')->iconButton()->tooltip('PDF')->icon('heroicon-o-document-arrow-down')->url(fn (CommissionReceipt $r) => url("/admin-files/receipt/{$r->id}"), shouldOpenInNewTab: true),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCommissionReceipts::route('/'), 'edit' => EditCommissionReceipt::route('/{record}/edit')];
    }
}
