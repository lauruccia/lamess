<?php

declare(strict_types=1);

namespace App\Filament\Resources\ComplianceCases;

use App\Domain\Access\Superadmin;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\ComplianceCases\Pages\ListComplianceCases;
use App\Filament\Resources\ComplianceCases\Pages\EditComplianceCase;
use App\Filament\Resources\ComplianceCases\Pages\ViewComplianceCase;
use App\Models\ComplianceCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Casi di conformità. */
class ComplianceCaseResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'compliance.view';
    }

    protected static function managePermission(): string
    {
        return 'compliance.manage';
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

    protected static ?string $model = ComplianceCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'caso di conformità';

    protected static ?string $pluralModelLabel = 'Casi di conformità';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $n = ComplianceCase::whereIn('status', ComplianceCase::OPEN_STATUSES)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function statusLabel(string $s): string
    {
        return ['open' => 'Aperto', 'investigating' => 'In istruttoria', 'sanctioned' => 'Chiuso con sanzione', 'dismissed' => 'Archiviato'][$s] ?? $s;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('number')->label('Numero')->searchable(),
            TextColumn::make('consultant.code')->label('Incaricato')->searchable(),
            TextColumn::make('category')->label('Categoria')->formatStateUsing(fn (string $state) => ComplianceCase::CATEGORIES[$state] ?? $state)->badge(),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => self::statusLabel($state))
                ->color(fn (string $state) => ['open' => 'warning', 'investigating' => 'info', 'sanctioned' => 'danger'][$state] ?? 'gray'),
            TextColumn::make('opened_at')->label('Aperto')->dateTime('d/m/Y', 'Europe/Rome')->sortable(),
        ])->filters([
            SelectFilter::make('status')->label('Stato')->options(['open' => 'Aperto', 'investigating' => 'In istruttoria', 'sanctioned' => 'Chiuso con sanzione', 'dismissed' => 'Archiviato']),
        ])->recordUrl(fn (ComplianceCase $c) => static::getUrl('view', ['record' => $c]));
    }

    public static function getPages(): array
    {
        return ['index' => ListComplianceCases::route('/'), 'view' => ViewComplianceCase::route('/{record}'), 'edit' => EditComplianceCase::route('/{record}/edit')];
    }
}
