<?php

declare(strict_types=1);

namespace App\Filament\Resources\Downloads;

use App\Domain\Content\DownloadService;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Downloads\Pages\CreateDownload;
use App\Filament\Resources\Downloads\Pages\EditDownload;
use App\Filament\Resources\Downloads\Pages\ListDownloads;
use App\Filament\Support\FormSection;
use App\Models\CompPlanVersion;
use App\Models\Download;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/** Materiali scaricabili con filigrana nominativa. */
class DownloadResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'content.manage';
    }

    protected static function managePermission(): string
    {
        return 'content.manage';
    }

    protected static ?string $model = Download::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'materiale';

    protected static ?string $pluralModelLabel = 'Materiali scaricabili';

    protected static ?int $navigationSort = 11;

    /** Completa tipo e dimensione del file caricato. */
    public static function withFileMeta(array $data): array
    {
        $disk = Storage::disk(DownloadService::DISK);
        if (! empty($data['file_path']) && $disk->exists($data['file_path'])) {
            $data['mime'] = $disk->mimeType($data['file_path']) ?: 'application/octet-stream';
            $data['size'] = $disk->size($data['file_path']);
            $data['original_name'] ??= basename($data['file_path']);
        }

        return $data;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FormSection::make('Il documento', 'Titolo, categoria e file da caricare.', [
                TextInput::make('title')->label('Titolo')->required()->maxLength(190),
                TextInput::make('category')->label('Categoria')->maxLength(60)->datalist(fn () => Download::query()->whereNotNull('category')->distinct()->pluck('category')->all()),
                Textarea::make('description')->label('Descrizione')->rows(3)->columnSpanFull(),
                FileUpload::make('file_path')->label('File')->required()->disk(DownloadService::DISK)->directory('downloads')->visibility('private')
                    ->storeFileNamesIn('original_name')->maxSize(51200)->helperText('Filigrana su PDF, JPG e PNG. Gli altri formati escono senza.')->columnSpanFull(),
            ]),
            FormSection::make('Chi può scaricarlo', 'Pubblico e qualifica minima richiesta.', [
                Select::make('audience')->label('Chi può scaricarlo')->options(Download::AUDIENCES)->default('consultants')->required(),
                Select::make('min_rank_code')->label('Qualifica minima')->placeholder('Nessuna')
                    ->options(fn () => collect(CompPlanVersion::inForce()?->data['ranks'] ?? [])->sortBy('sequence')->mapWithKeys(fn ($r) => [$r['code'] => $r['name']])->all()),
                Toggle::make('watermark')->label('Filigrana col nome di chi scarica')->default(true),
                Toggle::make('active')->label('Disponibile')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('title')->label('Titolo')->searchable(),
            TextColumn::make('category')->label('Categoria')->placeholder('—'),
            TextColumn::make('audience')->label('Per')->formatStateUsing(fn (string $state) => Download::AUDIENCES[$state] ?? $state)->badge(),
            TextColumn::make('min_rank_code')->label('Qualifica min.')->placeholder('—'),
            IconColumn::make('watermark')->label('Filigrana')->boolean(),
            IconColumn::make('active')->label('Attivo')->boolean(),
            TextColumn::make('logs_count')->label('Download')->counts('logs'),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListDownloads::route('/'), 'create' => CreateDownload::route('/create'), 'edit' => EditDownload::route('/{record}/edit')];
    }
}
