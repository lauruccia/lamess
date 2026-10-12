<?php

declare(strict_types=1);

namespace App\Filament\Resources\InheritanceCases;

use App\Domain\Access\Superadmin;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\InheritanceCases\Pages\ListInheritanceCases;
use App\Filament\Resources\InheritanceCases\Pages\EditInheritanceCase;
use App\Filament\Resources\InheritanceCases\Pages\ViewInheritanceCase;
use App\Models\InheritanceCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Pratiche di successione per decesso di un incaricato. */
class InheritanceCaseResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'network.view';
    }

    protected static function managePermission(): string
    {
        return 'network.manage';
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

    protected static ?string $model = InheritanceCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $modelLabel = 'successione';

    protected static ?string $pluralModelLabel = 'Successioni (decessi)';

    protected static ?int $navigationSort = 21;

    public const STATUS = ['reported' => 'Documenti da raccogliere', 'verified' => 'Verificata', 'liquidated' => 'Compensi liquidati', 'closed' => 'Chiusa'];

    public static function getNavigationBadge(): ?string
    {
        $n = InheritanceCase::where('status', '!=', 'closed')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Numero')->searchable(),
            TextColumn::make('consultant.code')->label('Codice')->searchable(),
            TextColumn::make('death_date')->label('Decesso')->date('d/m/Y'),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => self::STATUS[$state] ?? $state),
            TextColumn::make('outcome')->label('Codice')->formatStateUsing(fn (?string $state) => ['succession' => 'Subentro di un erede', 'termination' => 'Cessato'][$state] ?? 'Da decidere')->badge(),
            TextColumn::make('estate_amount')->label('Compensi €')->numeric(2)->placeholder('—'),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(self::STATUS),
        ])->recordUrl(fn (InheritanceCase $c) => static::getUrl('view', ['record' => $c]));
    }

    public static function getPages(): array
    {
        return ['index' => ListInheritanceCases::route('/'), 'view' => ViewInheritanceCase::route('/{record}'), 'edit' => EditInheritanceCase::route('/{record}/edit')];
    }
}
