<?php

declare(strict_types=1);

namespace App\Filament\Resources\NewsPosts;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\NewsPosts\Pages\CreateNewsPost;
use App\Filament\Resources\NewsPosts\Pages\EditNewsPost;
use App\Filament\Resources\NewsPosts\Pages\ListNewsPosts;
use App\Models\NewsPost;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
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
use Illuminate\Support\Str;
use UnitEnum;

/** Novità per incaricati e clienti. */
class NewsPostResource extends Resource
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

    protected static ?string $model = NewsPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static string|UnitEnum|null $navigationGroup = 'Assistenza';

    protected static ?string $modelLabel = 'novità';

    protected static ?string $pluralModelLabel = 'Novità';

    protected static ?int $navigationSort = 10;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->label('Titolo')->required()->maxLength(190)->live(onBlur: true)
                ->afterStateUpdated(fn ($state, $set, $get) => $get('slug') ? null : $set('slug', Str::slug((string) $state))),
            TextInput::make('slug')->label('Indirizzo (slug)')->required()->alphaDash()->unique(ignoreRecord: true),
            TextInput::make('excerpt')->label('Anteprima')->maxLength(300),
            Textarea::make('body')->label('Testo')->required()->rows(10)->columnSpanFull(),
            Select::make('audience')->label('A chi è rivolta')->options(NewsPost::AUDIENCES)->default('all')->required(),
            Select::make('status')->label('Stato')->options(['draft' => 'Bozza', 'published' => 'Pubblicata'])->default('draft')->required(),
            DateTimePicker::make('published_at')->label('Pubblica dal')->seconds(false)->helperText('Vuoto: subito, appena è pubblicata.'),
            Toggle::make('pinned')->label('In evidenza'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('title')->label('Titolo')->searchable()->limit(60),
            TextColumn::make('audience')->label('Rivolta a')->formatStateUsing(fn (string $state) => NewsPost::AUDIENCES[$state] ?? $state)->badge(),
            TextColumn::make('status')->label('Stato')->badge()->formatStateUsing(fn (string $state) => $state === 'published' ? 'Pubblicata' : 'Bozza')->color(fn (string $state) => $state === 'published' ? 'success' : 'gray'),
            TextColumn::make('published_at')->label('Dal')->dateTime('d/m/Y H:i', 'Europe/Rome'),
            IconColumn::make('pinned')->label('Evidenza')->boolean(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNewsPosts::route('/'), 'create' => CreateNewsPost::route('/create'), 'edit' => EditNewsPost::route('/{record}/edit')];
    }
}
