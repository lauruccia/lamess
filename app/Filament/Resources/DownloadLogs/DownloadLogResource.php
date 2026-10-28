<?php

declare(strict_types=1);

namespace App\Filament\Resources\DownloadLogs;

use App\Domain\Access\Superadmin;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\DownloadLogs\Pages\ListDownloadLogs;
use App\Filament\Resources\DownloadLogs\Pages\EditDownloadLog;
use App\Models\DownloadLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Chi ha scaricato cosa e se il file è uscito con la filigrana. */
class DownloadLogResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'content.view';
    }

    protected static function managePermission(): string
    {
        return 'content.manage';
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

    protected static ?string $model = DownloadLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'download';

    protected static ?string $pluralModelLabel = 'Registro download';

    protected static ?int $navigationSort = 13;

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i', 'Europe/Rome')->sortable(),
            TextColumn::make('download.title')->label('Materiale')->searchable(),
            TextColumn::make('user.name')->label('Utente')->searchable(),
            TextColumn::make('consultant.code')->label('Codice')->placeholder('—'),
            IconColumn::make('watermarked')->label('Con filigrana')->boolean(),
            TextColumn::make('note')->label('Nota')->placeholder('—')->wrap(),
            TextColumn::make('ip')->label('IP')->toggleable(isToggledHiddenByDefault: true),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDownloadLogs::route('/'), 'edit' => EditDownloadLog::route('/{record}/edit')];
    }
}
