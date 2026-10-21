<?php

declare(strict_types=1);

namespace App\Filament\Resources\WalletTransactions;

use App\Domain\Access\Superadmin;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\WalletTransactions\Pages\ListWalletTransactions;
use App\Filament\Resources\WalletTransactions\Pages\EditWalletTransaction;
use App\Models\WalletTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Registro dei movimenti dei wallet (sola lettura; le rettifiche si fanno dal pulsante in alto). */
class WalletTransactionResource extends Resource
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

    protected static ?string $model = WalletTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $modelLabel = 'movimento della Tasca';

    protected static ?string $pluralModelLabel = 'Movimenti Tasca';

    protected static ?int $navigationSort = 6;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label('Data')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
            TextColumn::make('consultant.code')->label('Codice')->searchable(),
            TextColumn::make('consultant.name')->label('Incaricato')->searchable(),
            TextColumn::make('type')->label('Tipo')->formatStateUsing(fn (string $state) => WalletTransaction::TYPES[$state] ?? $state)->badge(),
            TextColumn::make('amount')->label('Importo €')->numeric(2),
            TextColumn::make('balance_after')->label('Saldo €')->numeric(2),
            TextColumn::make('description')->label('Descrizione')->limit(60),
        ])->filters([
            SelectFilter::make('type')->label('Tipo')->options(WalletTransaction::TYPES),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWalletTransactions::route('/'), 'edit' => EditWalletTransaction::route('/{record}/edit')];
    }
}
