<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductCategories;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\ProductCategories\Pages\CreateProductCategory;
use App\Filament\Resources\ProductCategories\Pages\EditProductCategory;
use App\Filament\Resources\ProductCategories\Pages\ListProductCategories;
use App\Models\Product;
use App\Models\ProductCategory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Categorie («collezioni») del negozio: raggruppano i prodotti in vetrina e nelle regole di sconto.
 * Ogni categoria può avere sottocategorie (un solo livello): in lista compaiono sotto la madre.
 */
class ProductCategoryResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'catalog.manage';
    }

    protected static function managePermission(): string
    {
        return 'catalog.manage';
    }

    protected static ?string $model = ProductCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'categoria';

    protected static ?string $pluralModelLabel = 'Categorie';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Categoria')->columns(2)->schema([
                TextInput::make('name')->label('Nome')->required()->maxLength(80),
                Select::make('parent_id')->label('Categoria madre')->native(false)->searchable()
                    ->options(fn ($record) => ProductCategory::rootOptions($record?->getKey()))
                    ->placeholder('Nessuna: è una categoria principale')
                    ->default(fn () => ($p = request()->query('parent')) && ProductCategory::roots()->whereKey($p)->exists() ? (int) $p : null)
                    ->disabled(fn ($record) => (bool) $record?->children()->exists())
                    ->helperText(fn ($record) => $record?->children()->exists()
                        ? 'Ha delle sottocategorie, quindi resta una categoria principale.'
                        : 'Scegline una per creare una sottocategoria (es. «Creme» dentro «Viso»). Si può scendere di un solo livello.'),
                TextInput::make('slug')->label('Indirizzo della pagina')->prefix('/negozio?categoria=')->unique(ignoreRecord: true)->maxLength(80)
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? Str::slug((string) $state) : null)->helperText('Vuoto: si genera dal nome.'),
                TextInput::make('sort_order')->label('Posizione')->numeric()->integer()->default(0)->helperText('Più basso = prima, tra le categorie dello stesso livello.'),
                Textarea::make('description')->label('Descrizione')->rows(3)->columnSpanFull()->helperText('Compare in cima alla pagina della categoria.'),
            ]),
            Section::make('Aspetto e visibilità')->columns(2)->schema([
                FileUpload::make('image_path')->label('Immagine')->image()->maxSize(4096)->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->disk('local')->directory('shop/categories')->visibility('private'),
                Toggle::make('active')->label('Visibile nel negozio')->default(true)
                    ->helperText('Spenta: sparisce dal negozio insieme alle sue sottocategorie. I prodotti restano in vendita.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('parent')->inTreeOrder())
            ->columns([
                ImageColumn::make('image')->label('')->state(fn (ProductCategory $r) => $r->image_path ? Product::mediaUrl($r->image_path) : null)->imageSize(44)->extraImgAttributes(['style' => 'object-fit:cover;border-radius:8px']),
                TextColumn::make('name')->label('Categoria')->searchable()
                    ->formatStateUsing(fn (string $state, ProductCategory $r) => $r->isSubcategory() ? '↳ '.$state : $state)
                    ->weight(fn (ProductCategory $r) => $r->isSubcategory() ? FontWeight::Medium : FontWeight::SemiBold)
                    ->extraAttributes(fn (ProductCategory $r) => $r->isSubcategory() ? ['style' => 'padding-left:1.5rem'] : [])
                    ->description(fn (ProductCategory $r) => $r->isSubcategory() && ! $r->parent?->active ? 'Nascosta: la categoria madre è spenta' : null),
                TextColumn::make('children_count')->label('Sottocategorie')->counts('children')->formatStateUsing(fn ($state) => $state ?: '—'),
                TextColumn::make('products_count')->label('Prodotti')->counts('products'),
                ToggleColumn::make('active')->label('Visibile')->disabled(fn () => ! auth()->user()?->can('catalog.manage')),
            ])
            ->filters([
                Filter::make('only_roots')->label('Solo categorie principali')->toggle()->query(fn (Builder $q) => $q->roots()),
                SelectFilter::make('parent_id')->label('Sottocategorie di…')->options(fn () => ProductCategory::rootOptions()),
                TernaryFilter::make('active')->label('Visibilità')->trueLabel('Visibili')->falseLabel('Nascoste')->placeholder('Tutte'),
            ])
            ->recordActions([
                Action::make('addSubcategory')->iconButton()->icon(Heroicon::OutlinedPlusCircle)->tooltip('Aggiungi sottocategoria')
                    ->visible(fn (ProductCategory $r) => ! $r->isSubcategory() && static::canCreate())
                    ->url(fn (ProductCategory $r) => static::getUrl('create', ['parent' => $r->getKey()])),
                EditAction::make()->iconButton()->tooltip('Modifica'),
                DeleteAction::make()->iconButton()->tooltip('Elimina')
                    ->modalDescription('I prodotti della categoria restano nel negozio, senza categoria.')
                    ->before(static::refuseDeleteWithSubcategories()),
            ])
            ->emptyStateHeading('Nessuna categoria')
            ->emptyStateDescription('Crea la prima categoria; poi, dalla sua riga, potrai aggiungere le sottocategorie.')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    /** Una categoria con sottocategorie non si elimina: prima si eliminano o si spostano le figlie. */
    public static function refuseDeleteWithSubcategories(): \Closure
    {
        return function (DeleteAction $action, ProductCategory $record): void {
            $n = $record->children()->count();
            if ($n > 0) {
                Notification::make()->warning()->title('Questa categoria ha '.($n === 1 ? 'una sottocategoria' : "{$n} sottocategorie"))
                    ->body('Elimina o sposta prima le sottocategorie, poi potrai eliminare la categoria.')->send();
                $action->cancel();
            }
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListProductCategories::route('/'), 'create' => CreateProductCategory::route('/create'), 'edit' => EditProductCategory::route('/{record}/edit')];
    }
}
