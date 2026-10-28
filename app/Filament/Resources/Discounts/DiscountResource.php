<?php

declare(strict_types=1);

namespace App\Filament\Resources\Discounts;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Discounts\Pages\CreateDiscount;
use App\Filament\Resources\Discounts\Pages\EditDiscount;
use App\Filament\Resources\Discounts\Pages\ListDiscounts;
use App\Models\Discount;
use App\Models\Product;
use App\Models\ProductCategory;
use BackedEnum;
use Filament\Actions\Action as FormAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

/** Sconti: codici promozionali e sconti automatici (percentuale, importo fisso, scaglioni di quantità, spedizione gratuita). */
class DiscountResource extends Resource
{
    use GatedByPermission;

    protected static function viewPermission(): string
    {
        return 'catalog.view';
    }

    protected static function managePermission(): string
    {
        return 'catalog.manage';
    }

    protected static ?string $model = Discount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'sconto';

    protected static ?string $pluralModelLabel = 'Sconti';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $isType = fn (string ...$types) => fn (Get $get) => in_array($get('type'), $types, true);

        return $schema->components([
            Section::make('Che sconto è')->columns(2)->schema([
                TextInput::make('name')->label('Nome (solo per te)')->required()->maxLength(120)->placeholder('Es. Benvenuto -10%, Estate 3x2…'),
                Select::make('type')->label('Tipo di sconto')->options(Discount::TYPES)->required()->live()->default(Discount::PERCENT),
                ToggleButtons::make('method')->label('Come si ottiene')->options(['code' => 'Con un codice', 'automatic' => 'Automatico'])->icons(['code' => 'heroicon-o-ticket', 'automatic' => 'heroicon-o-bolt'])
                    ->inline()->default('code')->live()->dehydrated(false)
                    ->afterStateHydrated(fn (ToggleButtons $c, ?Model $record) => $c->state($record && blank($record->code) ? 'automatic' : 'code'))
                    ->helperText('Con codice: il cliente lo scrive nel carrello. Automatico: scatta da solo quando il carrello rispetta le condizioni.'),
                TextInput::make('code')->label('Codice')->maxLength(40)->visible(fn (Get $get) => $get('method') === 'code')
                    ->required(fn (Get $get) => $get('method') === 'code')->dehydrateStateUsing(fn ($state, Get $get) => $get('method') === 'code' ? $state : null)->dehydratedWhenHidden()
                    ->unique(ignoreRecord: true)->rule('regex:/^[A-Za-z0-9 _-]+$/')
                    ->suffixAction(FormAction::make('generate')->icon('heroicon-o-sparkles')->tooltip('Genera un codice')->action(fn (Set $set) => $set('code', Str::upper(Str::random(8)))))
                    ->helperText('Maiuscole e minuscole non contano.'),
                TextInput::make('value')->label(fn (Get $get) => $get('type') === Discount::FIXED ? 'Sconto in € (IVA inclusa)' : 'Sconto in %')->numeric()->minValue(0.01)->default(10)
                    ->maxValue(fn (Get $get) => $get('type') === Discount::PERCENT ? 100 : 100000)
                    ->required($isType(Discount::PERCENT, Discount::FIXED))->visible($isType(Discount::PERCENT, Discount::FIXED))
                    ->dehydrateStateUsing(fn ($state, Get $get) => in_array($get('type'), [Discount::PERCENT, Discount::FIXED], true) ? $state : 0)->dehydratedWhenHidden(),
                Repeater::make('tiers')->label('Scaglioni')->visible($isType(Discount::TIERED))->required($isType(Discount::TIERED))->minItems(1)->columnSpanFull()
                    ->addActionLabel('Aggiungi scaglione')->defaultItems(2)
                    ->helperText('Più pezzi dei prodotti interessati nel carrello, più sconto: si applica lo scaglione più alto raggiunto.')
                    ->schema([
                        TextInput::make('min_qty')->label('Da quanti pezzi')->numeric()->integer()->minValue(2)->required(),
                        TextInput::make('percent')->label('Sconto %')->numeric()->minValue(0.01)->maxValue(100)->required(),
                    ])->columns(2),
            ]),
            Section::make('A cosa si applica')->columns(2)->schema([
                Select::make('applies_to')->label('Prodotti interessati')->options(['all' => 'Tutto il carrello', 'products' => 'Prodotti scelti', 'categories' => 'Categorie scelte'])->default('all')->required()->live()->columnSpanFull(),
                Select::make('product_ids')->label('Prodotti')->multiple()->searchable()->preload()->options(fn () => Product::whereNull('parent_id')->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn (Get $get) => $get('applies_to') === 'products')->required(fn (Get $get) => $get('applies_to') === 'products')->columnSpanFull(),
                Select::make('category_ids')->label('Categorie')->multiple()->searchable()->preload()->options(fn () => ProductCategory::treeOptions())
                    ->visible(fn (Get $get) => $get('applies_to') === 'categories')->required(fn (Get $get) => $get('applies_to') === 'categories')->columnSpanFull(),
                TextInput::make('min_subtotal')->label('Spesa minima (IVA inclusa) €')->numeric()->minValue(0)->helperText('Sui prodotti interessati. Vuoto: nessun minimo.'),
                TextInput::make('min_qty')->label('Pezzi minimi')->numeric()->integer()->minValue(1)->helperText('Sui prodotti interessati. Vuoto: nessun minimo.'),
            ]),
            Section::make('Chi, quando, quante volte')->columns(2)->schema([
                Select::make('audience')->label('Chi ne ha diritto')->options(Discount::AUDIENCES)->default('all')->required(),
                Toggle::make('combinable')->label('Cumulabile con altri sconti')->inline(false)
                    ->helperText('Spento: se ce n\'è un altro applicabile vince il più conveniente. Due sconti si sommano solo se entrambi sono cumulabili.'),
                DateTimePicker::make('starts_at')->label('Inizia il')->seconds(false)->timezone('Europe/Rome'),
                DateTimePicker::make('ends_at')->label('Finisce il')->seconds(false)->timezone('Europe/Rome')->after('starts_at'),
                TextInput::make('usage_limit')->label('Utilizzi totali massimi')->numeric()->integer()->minValue(1)->helperText('Vuoto: illimitati.'),
                TextInput::make('usage_limit_per_user')->label('Utilizzi massimi per cliente')->numeric()->integer()->minValue(1)->helperText('Vuoto: illimitati. Richiede l\'accesso.'),
                Toggle::make('active')->label('Attivo')->default(true)->helperText('Spento: lo sconto non si applica più, ma resta registrato.'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('name')->label('Sconto')->searchable()->description(fn (Discount $r) => $r->isAutomatic() ? 'Automatico' : null),
            TextColumn::make('code')->label('Codice')->badge()->color('gray')->copyable()->placeholder('—')->searchable(),
            TextColumn::make('type')->label('Tipo')->state(fn (Discount $r) => self::summary($r)),
            TextColumn::make('redemptions_count')->label('Utilizzi')->counts('redemptions')->formatStateUsing(fn ($state, Discount $r) => $state.($r->usage_limit ? ' / '.$r->usage_limit : '')),
            TextColumn::make('status')->label('Stato')->badge()->state(fn (Discount $r) => $r->statusLabel())
                ->color(fn (string $state) => match ($state) {
                    'Attivo' => 'success', 'Programmato' => 'info', 'Scaduto', 'Esaurito' => 'warning', default => 'gray',
                }),
            TextColumn::make('ends_at')->label('Fino al')->dateTime('d/m/Y H:i', 'Europe/Rome')->placeholder('—')->sortable(),
            ToggleColumn::make('active')->label('Attivo')->disabled(fn () => ! auth()->user()?->can('catalog.manage')),
        ])->filters([
            SelectFilter::make('type')->label('Tipo')->options(Discount::TYPES),
            SelectFilter::make('method')->label('Come si ottiene')->options(['code' => 'Con codice', 'automatic' => 'Automatici'])
                ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                    'code' => $q->whereNotNull('code'), 'automatic' => $q->whereNull('code'), default => $q,
                }),
        ])->recordActions([
            EditAction::make()->iconButton()->tooltip('Modifica'),
            DeleteAction::make()->iconButton()->tooltip('Elimina')->modalDescription('Gli ordini già fatti con questo sconto restano invariati. Per fermarlo senza cancellarlo, disattivalo.'),
        ]);
    }

    private static function summary(Discount $d): string
    {
        return match ($d->type) {
            Discount::PERCENT => rtrim(rtrim((string) $d->value, '0'), '.').'% di sconto',
            Discount::FIXED => '€ '.number_format((float) $d->value, 2, ',', '.').' di sconto',
            Discount::TIERED => collect((array) $d->tiers)->sortBy('min_qty')->map(fn ($t) => ($t['min_qty'] ?? '?').' pz → '.rtrim(rtrim((string) ($t['percent'] ?? 0), '0'), '.').'%')->implode(' · '),
            Discount::FREE_SHIPPING => 'Spedizione gratuita'.($d->min_subtotal ? ' da € '.number_format((float) $d->min_subtotal, 0, ',', '.') : ''),
            default => $d->type,
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListDiscounts::route('/'), 'create' => CreateDiscount::route('/create'), 'edit' => EditDiscount::route('/{record}/edit')];
    }
}
