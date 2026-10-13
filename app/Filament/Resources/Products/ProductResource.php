<?php

declare(strict_types=1);

namespace App\Filament\Resources\Products;

use App\Domain\Engine\Support\Num;
use App\Domain\Plan\LegacyPlan;
use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Models\CompPlanVersion;
use App\Models\Product;
use App\Models\ProductCategory;
use BackedEnum;
use Brick\Math\RoundingMode;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/** Prodotti del negozio (RF-040…043): scheda stile e-commerce con immagini, prezzo al pubblico, giacenza, pubblicazione, punti, kit. */
class ProductResource extends Resource
{
    use GatedByPermission;

    public const LOW_STOCK = 5;

    protected static function viewPermission(): string
    {
        return 'catalog.manage';
    }

    protected static function managePermission(): string
    {
        return 'catalog.manage';
    }

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'prodotto';

    protected static ?string $pluralModelLabel = 'Prodotti';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /** Dal prezzo IVA inclusa al netto con 8 decimali (il netto resta esatto, come nelle fatture). */
    public static function netFromGross(mixed $gross, mixed $vatRate): ?string
    {
        $gross = str_replace(',', '.', trim((string) $gross));
        if ($gross === '' || ! is_numeric($gross)) {
            return null;
        }

        return (string) Num::of($gross)->multipliedBy(100)->dividedBy(Num::of('100')->plus(Num::of((string) (is_numeric($vatRate) ? $vatRate : 22))), 8, RoundingMode::HalfUp);
    }

    public static function grossFromNet(mixed $net, mixed $vatRate): ?string
    {
        if (blank($net) || ! is_numeric($net)) {
            return null;
        }

        return (string) Num::money(Num::of((string) $net)->multipliedBy(Num::of('100')->plus(Num::of((string) (is_numeric($vatRate) ? $vatRate : 22))))->dividedBy(100, 10, RoundingMode::HalfUp));
    }

    /**
     * Riepilogo in parole dei dati di commissione del prodotto, con il V.A.P. in euro sul prezzo al pubblico indicato.
     */
    public static function commissionSummary(Get $get): string
    {
        $mode = $get('vap_mode');
        $value = str_replace(',', '.', trim((string) $get('vap_value')));
        $price = str_replace(',', '.', trim((string) $get('gross_price')));
        $eur = static fn (string $n): string => number_format((float) $n, 2, ',', '.').' €';
        $clients = max(1, (int) $get('customer_count'));
        $months = max(1, (int) $get('customer_validity_months'));
        $validity = $clients.($clients === 1 ? ' cliente' : ' clienti').' per '.$months.($months === 1 ? ' mese' : ' mesi').'.';

        if (in_array($mode, [Product::VAP_PERCENT, Product::VAP_FIXED], true) && is_numeric($value)) {
            if ($mode === Product::VAP_FIXED) {
                return 'V.A.P. '.$eur($value).' per pezzo. Vale '.$validity;
            }
            $of = is_numeric($price) && (float) $price > 0 ? ' di '.$eur($price).' = '.$eur((string) Num::money(Num::of($price)->multipliedBy($value)->dividedBy(100, 10, RoundingMode::HalfUp))) : ' del prezzo';

            return 'V.A.P. '.rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',').' %'.$of.'. Vale '.$validity;
        }
        $share = CompPlanVersion::inForce()?->plan()->kosmos?->vapShare;
        $plan = $share !== null ? rtrim(rtrim(number_format((float) (string) $share->multipliedBy(100), 2, ',', ''), '0'), ',').' % del prezzo' : 'la quota del piano';

        return 'V.A.P. come da piano ('.$plan.'). Vale '.$validity;
    }

    public const MAX_VARIANTS = 60;

    /**
     * Combinazioni delle opzioni (formato × colore…): aggiunge solo quelle mancanti e lascia intatte le varianti già presenti.
     *
     * @param  array<int,mixed>  $options  [{name, values[]}]
     * @param  array<string,mixed>  $existing  righe del ripetitore, per chiave
     * @return array{0: array<string,mixed>, 1: int, 2: bool} righe, quante nuove, troppe combinazioni
     */
    public static function generateVariantRows(array $options, array $existing, string $parentSku, string $grossPrice): array
    {
        $groups = [];
        foreach ($options as $o) {
            $name = trim((string) ($o['name'] ?? ''));
            $values = array_values(array_unique(array_filter(array_map(fn ($v) => trim((string) $v), (array) ($o['values'] ?? [])), fn ($v) => $v !== '')));
            if ($name !== '' && $values !== []) {
                $groups[$name] = $values;
            }
        }
        if ($groups === []) {
            return [$existing, 0, false];
        }
        $combos = [[]];
        foreach ($groups as $name => $values) {
            $next = [];
            foreach ($combos as $c) {
                foreach ($values as $v) {
                    $next[] = $c + [$name => $v];
                }
            }
            $combos = $next;
            if (count($combos) > self::MAX_VARIANTS) {
                return [$existing, 0, true];
            }
        }
        $have = [];
        foreach ($existing as $row) {
            $have[json_encode(array_values((array) ($row['variant_options'] ?? [])), JSON_UNESCAPED_UNICODE)] = true;
        }
        $added = 0;
        foreach ($combos as $combo) {
            if (isset($have[json_encode(array_values($combo), JSON_UNESCAPED_UNICODE)])) {
                continue;
            }
            $existing[(string) Str::uuid()] = [
                'variant_options' => $combo, 'variant_label' => implode(' / ', $combo),
                'sku' => $parentSku !== '' ? Str::upper(Str::limit($parentSku, 30, '').'-'.Str::slug(implode('-', $combo))) : null,
                'price_gross' => $grossPrice !== '' ? $grossPrice : null, 'compare_gross' => null, 'stock_qty' => null, 'active' => true,
            ];
            $added++;
        }

        return [$existing, $added, false];
    }

    /**
     * Dati di una variante pronti per il salvataggio: dal prezzo IVA inclusa al netto (con l'aliquota del principale).
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public static function variantPayload(array $data, mixed $livewire): array
    {
        $vat = $livewire->data['vat_rate'] ?? 22;
        $data['net_price'] = self::netFromGross($data['price_gross'] ?? null, $vat) ?? 0;
        $data['compare_at_net'] = filled($data['compare_gross'] ?? null) ? self::netFromGross($data['compare_gross'], $vat) : null;
        $data['stock_qty'] = filled($data['stock_qty'] ?? null) ? (int) $data['stock_qty'] : null;
        $data['vat_rate'] = $vat;
        unset($data['price_gross'], $data['compare_gross']);
        if (blank($data['sku'] ?? null)) {
            unset($data['sku']);
        }

        return $data;
    }

    public const IMAGE_MAX_PX = 1600;

    /** Salva la foto caricata ridimensionata (max 1600 px sul lato lungo) e compressa in WebP: pesa una frazione dell'originale. Senza WebP/GD salva il file com'è. */
    public static function storeOptimized(TemporaryUploadedFile $file): string
    {
        $dir = 'shop/products';
        // Già passata dall'editor del browser (WebP entro 1600 px): non serve ricomprimerla.
        $info = @getimagesize($file->getRealPath());
        if ($info !== false && $info[2] === IMAGETYPE_WEBP && max($info[0], $info[1]) <= self::IMAGE_MAX_PX) {
            return (string) $file->storeAs($dir, Str::uuid().'.webp', 'local');
        }
        $img = function_exists('imagewebp') ? @imagecreatefromstring((string) file_get_contents($file->getRealPath())) : false;
        if ($img !== false) {
            $w = imagesx($img);
            $h = imagesy($img);
            if (max($w, $h) > self::IMAGE_MAX_PX) {
                $ratio = self::IMAGE_MAX_PX / max($w, $h);
                $scaled = imagescale($img, max(1, (int) round($w * $ratio)), max(1, (int) round($h * $ratio)));
                if ($scaled !== false) {
                    $img = $scaled;
                }
            }
            imagepalettetotruecolor($img);
            imagealphablending($img, true);
            imagesavealpha($img, true);
            ob_start();
            imagewebp($img, null, 82);
            $bin = (string) ob_get_clean();
            if ($bin !== '') {
                $path = $dir.'/'.Str::uuid().'.webp';
                Storage::disk('local')->put($path, $bin, 'private');

                return $path;
            }
        }

        return (string) $file->store($dir, 'local');
    }

    public static function form(Schema $schema): Schema
    {
        $imageTypes = ['image/jpeg', 'image/png', 'image/webp'];

        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 12])->columnSpanFull()->extraAttributes(['class' => 'lm-product-form'])->schema([
                // ───────── Colonna principale: tutto ciò che serve per mettere in vendita il prodotto ─────────
                Group::make()->columnSpan(['xl' => 9])->schema([
                    Section::make('Scheda prodotto')->icon('heroicon-o-sparkles')->columns(['default' => 1, 'md' => 3])->schema([
                        TextInput::make('name')->label('Nome del prodotto')->required()->maxLength(150)->autofocus()->columnSpan(['md' => 2]),
                        TextInput::make('sku')->label('Codice (SKU)')->required()->unique(ignoreRecord: true)->columnSpan(['md' => 1]),
                        Textarea::make('description')->label('Descrizione breve')->rows(2)->maxLength(500)->columnSpanFull()
                            ->helperText('Compare sotto il nome nel catalogo e in cima alla scheda.'),
                    ]),
                    Section::make('Immagini')->icon('heroicon-o-photo')->description('La prima è la copertina. Trascina per cambiare l\'ordine; quando scegli un file si apre «Sistema l\'immagine»: ritagli, ingrandisci o ruoti. Le foto escono ridimensionate (max 1600 px) e compresse in WebP.')->schema([
                        FileUpload::make('images')->label('')->multiple()->image()->reorderable()->maxFiles(8)->maxSize(4096)
                            ->acceptedFileTypes($imageTypes)
                            ->disk('local')->directory('shop/products')->visibility('private')->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => self::storeOptimized($file))->panelLayout('grid'),
                    ]),
                    Section::make('Prezzo e disponibilità')->icon('heroicon-o-currency-euro')->columns(['default' => 1, 'md' => 2, '2xl' => 4])->schema([
                        TextInput::make('gross_price')->label('Prezzo al pubblico (IVA incl.)')->prefix('€')->numeric()->dehydrated(false)->minValue(0)->live(onBlur: true)
                            ->afterStateHydrated(fn (TextInput $c, ?Model $record) => $c->state($record ? self::grossFromNet($record->net_price, $record->vat_rate) : null))
                            ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('net_price', self::netFromGross($state, $get('vat_rate'))))
                            ->helperText(fn (Get $get) => filled($get('variants')) ? 'Con le varianti ogni variante ha il suo prezzo: questo valore si aggiorna da solo al più basso.' : 'È il prezzo che vede il cliente.'),
                        TextInput::make('compare_at_gross')->label('Prezzo di listino barrato (IVA incl.)')->prefix('€')->numeric()->dehydrated(false)->minValue(0)->live(onBlur: true)
                            ->afterStateHydrated(fn (TextInput $c, ?Model $record) => $c->state($record?->compare_at_net !== null ? self::grossFromNet($record->compare_at_net, $record->vat_rate) : null))
                            ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('compare_at_net', filled($state) ? self::netFromGross($state, $get('vat_rate')) : null))
                            ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get): void {
                                if (filled($value) && filled($get('gross_price')) && (float) $value <= (float) $get('gross_price')) {
                                    $fail('Il listino barrato deve essere più alto del prezzo al pubblico.');
                                }
                            })
                            ->helperText('Facoltativo: se è più alto del prezzo, nel negozio compare barrato con il risparmio.'),
                        TextInput::make('vat_rate')->label('Aliquota IVA')->suffix('%')->numeric()->required()->default(22)->datalist(['22', '10', '5', '4', '0'])->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('net_price', self::netFromGross($get('gross_price'), $state) ?? $get('net_price'))),
                        TextInput::make('net_price')->label('Prezzo netto (calcolato)')->prefix('€')->numeric()->readOnly()->required(fn (Get $get) => blank($get('variants')))->default(0)->minValue(0)->live(onBlur: true)
                            ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)
                            ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('gross_price', self::grossFromNet($state, $get('vat_rate'))))
                            ->helperText('Si calcola da solo: non serve compilarlo.'),
                        // Listino netto: calcolato dal listino IVA incl.; resta nel form (non visibile) per la validazione.
                        TextInput::make('compare_at_net')->numeric()->minValue(0)->gt('net_price')->nullable()->hiddenLabel()->extraFieldWrapperAttributes(['class' => 'hidden'])->columnSpan(['default' => 'hidden']),
                        TextInput::make('shipping_gross')->label('Costo di spedizione del prodotto (IVA incl.)')->prefix('€')->numeric()->minValue(0)->dehydrated(false)->live(onBlur: true)
                            ->afterStateHydrated(fn (TextInput $c, ?Model $record) => $c->state($record?->shipping_net !== null ? self::grossFromNet($record->shipping_net, config('lamess.default_vat_rate')) : null))
                            ->afterStateUpdated(fn (Set $set, $state) => $set('shipping_net', filled($state) ? self::netFromGross($state, config('lamess.default_vat_rate')) : null))
                            ->helperText('Vuoto: vale la tariffa generale (Vendite → Spedizioni). 0: spedizione gratuita per questo prodotto. Con più prodotti nel carrello si paga la voce più alta.'),
                        Hidden::make('shipping_net'),
                        TextInput::make('weight_g')->label('Peso (g)')->numeric()->integer()->minValue(0)->nullable()->helperText('Serve per le tariffe di spedizione a fascia di peso.'),
                        Grid::make(['default' => 1, 'md' => 3])->columnSpanFull()->extraAttributes(['class' => 'lm-stock-row'])->schema([
                            Toggle::make('track_stock')->label('Tieni traccia della giacenza')->dehydrated(false)->live()->inline(false)
                                ->afterStateHydrated(fn (Toggle $c, ?Model $record) => $c->state($record?->stock_qty !== null))
                                ->helperText('Spento: il prodotto è sempre disponibile.'),
                            TextInput::make('stock_qty')->label('Pezzi disponibili')->numeric()->integer()->visible(fn (Get $get) => (bool) $get('track_stock'))
                                ->required(fn (Get $get) => (bool) $get('track_stock'))->dehydrateStateUsing(fn ($state, Get $get) => $get('track_stock') ? (int) $state : null)
                                ->dehydratedWhenHidden(),
                            Toggle::make('allow_backorder')->label('Vendi anche a giacenza esaurita')->visible(fn (Get $get) => (bool) $get('track_stock'))->inline(false),
                        ]),
                    ]),
                    Section::make('Commissioni e durata (Piano Incaricati)')->icon('heroicon-o-star')
                        ->description('Esempio: Box da 150 € con V.A.P. 100 € → i compensi si calcolano su 100 €. Un prodotto da 5 box può valere 5 clienti, uno da 10 box può valere 1 cliente per 12 mesi.')
                        ->columns(['default' => 1, 'md' => 3])->schema([
                            Select::make('vap_mode')->label('V.A.P. (importo su cui si calcolano le commissioni)')->native(false)->live()
                                ->options([Product::VAP_PERCENT => 'Percentuale del prezzo (IVA incl.)', Product::VAP_FIXED => 'Importo fisso per pezzo'])
                                ->placeholder('Come da piano (quota unica)')
                                ->afterStateUpdated(fn (Set $set, $state) => filled($state) ? null : $set('vap_value', null))
                                ->helperText('Vuoto = vale la quota V.A.P. impostata nel piano compensi.'),
                            TextInput::make('vap_value')->label(fn (Get $get) => $get('vap_mode') === Product::VAP_FIXED ? 'V.A.P. in euro per pezzo' : 'V.A.P. in % del prezzo')
                                ->numeric()->minValue(0)->maxValue(fn (Get $get) => $get('vap_mode') === Product::VAP_PERCENT ? 100 : null)->live(onBlur: true)
                                ->prefix(fn (Get $get) => $get('vap_mode') === Product::VAP_FIXED ? '€' : null)->suffix(fn (Get $get) => $get('vap_mode') === Product::VAP_PERCENT ? '%' : null)
                                ->visible(fn (Get $get) => filled($get('vap_mode')))->required(fn (Get $get) => filled($get('vap_mode')))
                                ->helperText('Percentuale: sul prezzo IVA inclusa già scontato. Importo fisso: moltiplicato per i pezzi acquistati.'),
                            TextInput::make('customer_count')->label('Quanti clienti vale')->numeric()->integer()->minValue(1)->maxValue(1000)->placeholder('1')->live(onBlur: true)
                                ->helperText('Quanti clienti conta per l\'incaricato chi compra questo prodotto (conta una volta per ordine, non per pezzo). Vuoto = 1.'),
                            TextInput::make('customer_validity_months')->label('Durata (mesi)')->numeric()->integer()->minValue(1)->maxValue(120)->placeholder('1')->live(onBlur: true)
                                ->helperText('Per quanti mesi vale come cliente, poi scade. Vuoto = 1 mese. Se il cliente riordina prima della scadenza, i mesi si sommano dalla scadenza.'),
                            Text::make(fn (Get $get): string => self::commissionSummary($get))->color('gray')->columnSpanFull(),
                        ]),
                    // ───────── Impostazioni facoltative, ordinate per tab: si aprono solo quando servono ─────────
                    Tabs::make('Altre impostazioni')->columnSpanFull()->tabs([
                        Tab::make('Descrizione completa')->icon('heroicon-o-document-text')->schema([
                            MarkdownEditor::make('details')->label('Ingredienti, modo d\'uso, avvertenze')
                                ->toolbarButtons(['bold', 'italic', 'heading', 'bulletList', 'orderedList', 'link', 'undo', 'redo'])
                                ->helperText('Formattazione semplice (Markdown).'),
                        ]),
                        Tab::make('Varianti')->icon('heroicon-o-swatch')->badge(fn (Get $get) => count((array) $get('variants')) ?: null)->schema([
                            Text::make('Servono se il prodotto esiste in più formati, taglie o colori. Se il prodotto è uno solo, lascia vuoto.')->color('gray'),
                            Repeater::make('options')->label('Opzioni')->maxItems(3)->addActionLabel('Aggiungi un\'opzione')->defaultItems(0)->columns(2)
                                ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                                ->schema([
                                    TextInput::make('name')->label('Nome dell\'opzione')->required()->maxLength(30)->datalist(['Formato', 'Taglia', 'Colore', 'Gusto', 'Confezione', 'Peso'])->placeholder('Formato'),
                                    TagsInput::make('values')->label('Valori (premi Invio dopo ciascuno)')->required()->placeholder('50 bustine')
                                        ->suggestions(['50 bustine', '100 bustine', '30 capsule', '60 capsule', '250 g', '500 g', '1 kg', '100 ml', '250 ml', '500 ml', 'XS', 'S', 'M', 'L', 'XL', 'Unica']),
                                ])
                                ->helperText('Esempio: opzione «Formato» con i valori «50 bustine» e «100 bustine». Con due opzioni (es. Formato e Colore) si creano tutte le combinazioni.'),
                            Actions::make([
                                Action::make('generate_variants')->label('Crea le varianti dalle opzioni')->icon('heroicon-o-sparkles')->color('gray')
                                    ->action(function (Get $get, Set $set): void {
                                        [$rows, $added, $tooMany] = self::generateVariantRows((array) $get('options'), (array) $get('variants'), (string) $get('sku'), (string) ($get('gross_price') ?? ''));
                                        if ($tooMany) {
                                            Notification::make()->title('Troppe combinazioni (massimo '.self::MAX_VARIANTS.'): riduci i valori.')->danger()->send();

                                            return;
                                        }
                                        $set('variants', $rows);
                                        Notification::make()->title($added > 0 ? "{$added} varianti create: controlla prezzi e giacenze, poi salva." : 'Nessuna variante nuova: ci sono già tutte.')->success()->send();
                                    }),
                            ]),
                            Repeater::make('variants')->label('Varianti')->relationship('variants')->columns(['default' => 2, 'xl' => 5])->itemLabel(fn (array $state): ?string => $state['variant_label'] ?? null)->addable(false)->reorderable()->orderColumn('sort_order')->defaultItems(0)->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                                $data['price_gross'] = self::grossFromNet($data['net_price'] ?? null, $data['vat_rate'] ?? 22);
                                $data['compare_gross'] = self::grossFromNet($data['compare_at_net'] ?? null, $data['vat_rate'] ?? 22);

                                return $data;
                            })->mutateRelationshipDataBeforeCreateUsing(fn (array $data, $livewire): array => self::variantPayload($data, $livewire))
                                ->mutateRelationshipDataBeforeSaveUsing(fn (array $data, $livewire): array => self::variantPayload($data, $livewire))
                                ->deleteAction(fn (Action $action) => $action->visible(function (array $arguments): bool {
                                    $key = (string) ($arguments['item'] ?? '');
                                    $variant = str_starts_with($key, 'record-') ? Product::find((int) substr($key, 7)) : null;

                                    return $variant === null || $variant->isDeletable();
                                }))
                                ->visible(fn (Get $get) => filled($get('variants')))
                                ->schema([
                                    Hidden::make('variant_label'),
                                    Hidden::make('variant_options'),
                                    TextInput::make('sku')->label('Codice (SKU)')->placeholder('automatico')->maxLength(60)->distinct()->unique(ignoreRecord: true),
                                    TextInput::make('price_gross')->label('Prezzo IVA incl.')->prefix('€')->numeric()->minValue(0.01)->required()->dehydrated(),
                                    TextInput::make('compare_gross')->label('Listino barrato')->prefix('€')->numeric()->minValue(0)->dehydrated(),
                                    TextInput::make('stock_qty')->label('Giacenza')->numeric()->integer()->minValue(0)->placeholder('sempre'),
                                    Toggle::make('active')->label('Pubblicata')->default(true)->inline(false),
                                    FileUpload::make('images')->label('Foto di questa variante (facoltativa)')->image()->imagePreviewHeight('110')->panelLayout('compact')->multiple()->maxFiles(1)->maxSize(4096)
                                        ->acceptedFileTypes($imageTypes)->disk('local')->directory('shop/products')->visibility('private')->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => self::storeOptimized($file))
                                        ->columnSpanFull()->helperText('Se la carichi, scegliendo questa variante il cliente vede la sua foto; altrimenti restano quelle del prodotto.'),
                                ])
                                ->helperText('Una variante già venduta non si può eliminare: nascondila. Giacenza vuota = sempre disponibile.'.(LegacyPlan::visible() ? ' I PV si calcolano dal prezzo di ogni variante.' : '')),
                        ]),
                        Tab::make('Kit')->icon('heroicon-o-gift')->badge(fn (Get $get) => $get('is_kit') ? 'attivo' : null)->schema([
                            Text::make('Un kit è una confezione che riunisce più prodotti venduti insieme a un prezzo unico (per esempio una box regalo con crema, scrub e profumo). Il cliente vede e paga un solo articolo; nell\'ordine compaiono anche i prodotti contenuti, a prezzo e PV zero.')->color('gray'),
                            Toggle::make('is_kit')->label('Questo prodotto è un kit')->live(),
                            Repeater::make('components')->label('Prodotti contenuti nel kit')->relationship()->visible(fn ($get) => (bool) $get('is_kit'))
                                ->addActionLabel('Aggiungi un prodotto')->columns(['default' => 1, 'md' => 4])
                                ->schema([
                                    Select::make('component_product_id')->label('Prodotto')->required()->searchable()->columnSpan(['md' => 3])->options(fn () => Product::where('is_kit', false)->whereDoesntHave('variants')->orderBy('name')->pluck('name', 'id'))
                                        ->helperText('Per un prodotto con varianti scegli quella precisa che entra nel kit.'),
                                    TextInput::make('qty')->label('Quantità')->numeric()->minValue(1)->default(1)->required(),
                                ]),
                        ]),
                        Tab::make('Punti (vecchio piano)')->icon('heroicon-o-star')->columns(1)->visible(fn (): bool => LegacyPlan::visible())->schema([
                            Section::make('Punti del vecchio piano')->description('Non servono al Piano Incaricati, che calcola sul V.A.P. (sezione «Commissioni e durata» sopra). Restano per il piano attuale a punti.')
                                ->columns(['default' => 1, 'md' => 3])->schema([
                                    TextInput::make('pv')->label('PV unitari')->numeric()->helperText('Vuoto = calcolo automatico'),
                                    TextInput::make('cv')->label('CV unitari')->numeric(),
                                    Toggle::make('promo_rank_double_cv')->label('Promo Rank (CV doppio)')->inline(false),
                                    Select::make('commission_base')->label('Base di calcolo dei PV')->options(['net' => 'Prezzo netto (imponibile)', 'gross' => 'Prezzo lordo (IVA inclusa)'])->default('net')->required(),
                                    TextInput::make('commissionable_pct')->label('Quota commissionabile (%)')->numeric()->minValue(0)->maxValue(100)->default(100)->required()
                                        ->helperText('Percentuale del prezzo su cui si calcolano i PV automatici.'),
                                    Select::make('pv_discount_mode')->label('PV quando il prodotto è scontato')->options(['inherit' => 'Come da politica del piano (D02)', 'proportional' => 'Proporzionali allo sconto', 'fixed' => 'Fissi, invariati'])->default('inherit')->required()
                                        ->helperText('Vale per i codici sconto, gli sconti automatici e gli scaglioni di quantità.'),
                                    Toggle::make('shipping_commissionable')->label('La spedizione genera PV')->helperText('Salvato per uso futuro: oggi la spedizione non genera PV (OPEN-18).'),
                                ]),
                        ]),
                        Tab::make('Google (SEO)')->icon('heroicon-o-magnifying-glass')->schema([
                            Text::make('Facoltativo: se vuoti si usano nome e descrizione breve.')->color('gray'),
                            TextInput::make('slug')->label('Indirizzo della pagina')->prefix('/negozio/')->unique(ignoreRecord: true)->maxLength(150)
                                ->helperText('Vuoto: si genera dal nome. Cambiarlo cambia il link del prodotto.')
                                ->dehydrateStateUsing(fn ($state) => filled($state) ? Str::slug((string) $state) : null),
                            TextInput::make('seo_title')->label('Titolo per Google')->maxLength(70),
                            Textarea::make('seo_description')->label('Descrizione per Google')->rows(2)->maxLength(300),
                        ]),
                    ]),
                ]),
                // ───────── Colonna laterale: pubblicazione e catalogo, sempre a portata di mano ─────────
                Group::make()->columnSpan(['xl' => 3])->extraAttributes(['class' => 'lm-product-side'])->schema([
                    Section::make('Pubblicazione')->icon('heroicon-o-eye')->schema([
                        Toggle::make('active')->label('Pubblicato nel negozio')->default(true)
                            ->helperText('Spento = nascosto: non compare nel negozio e non si può acquistare. Resta nello storico degli ordini.'),
                        Toggle::make('featured')->label('In evidenza')->helperText('Compare in alto nella vetrina.'),
                        TextInput::make('badge')->label('Etichetta')->maxLength(30)->placeholder('Novità, Bestseller…'),
                        Select::make('kmoney_percent')->label('% pagabile in KMoney')->default(100)->native(false)->selectablePlaceholder(false)
                            ->options([0 => 'Non accettato (solo carta/bonifico)', 25 => '25% in KMoney', 50 => '50% in KMoney', 75 => '75% in KMoney', 100 => '100% in KMoney'])
                            ->helperText('Quota del prezzo che il cliente può pagare col saldo KMoney. Le varianti usano la % del prodotto principale.'),
                    ]),
                    Section::make('Catalogo')->icon('heroicon-o-folder')->schema([
                        Select::make('category_id')->label('Categoria')->options(fn () => ProductCategory::treeOptions())->searchable()->preload()
                            ->helperText('Una sottocategoria compare anche nella sua categoria madre.')
                            ->createOptionForm([
                                TextInput::make('name')->label('Nome della categoria')->required(),
                                Select::make('parent_id')->label('Categoria madre (facoltativa)')->options(fn () => ProductCategory::rootOptions())->placeholder('Nessuna: categoria principale'),
                            ])
                            ->createOptionUsing(fn (array $data) => ProductCategory::create(['name' => $data['name'], 'parent_id' => $data['parent_id'] ?? null])->getKey()),
                        TextInput::make('sort_order')->label('Posizione in vetrina')->numeric()->integer()->default(0)->helperText('Più basso = prima.'),
                        TagsInput::make('countries')->label('Paesi (vuoto = tutti gli abilitati)')->placeholder('IT'),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('sort_order')->columns([
            ImageColumn::make('cover')->label('')->state(fn (Product $r) => $r->coverUrl())->imageSize(44)->extraImgAttributes(['style' => 'object-fit:cover;border-radius:8px']),
            TextColumn::make('name')->label('Prodotto')->searchable()->sortable()->description(fn (Product $r) => $r->sku)
                ->searchable(['name', 'sku']),
            TextColumn::make('category.name')->label('Categoria')->badge()->color('gray')->placeholder('—')->toggleable(),
            TextColumn::make('price')->label('Prezzo IVA incl. €')->alignEnd()
                ->state(function (Product $r): string {
                    $range = $r->hasVariants() ? $r->variantPriceRange() : null;

                    return $range === null ? $r->grossPrice() : ($range[0] === $range[1] ? $range[0] : "da {$range[0]}");
                })
                ->description(fn (Product $r) => $r->hasVariants() ? $r->variants->count().' varianti' : ($r->compareAtGross() ? 'listino '.$r->compareAtGross() : null)),
            TextColumn::make('net_price')->label('Netto €')->numeric(2)->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('stock_qty')->label('Giacenza')->state(function (Product $r): string {
                if ($r->hasVariants()) {
                    $v = $r->variants;

                    return $v->contains(fn (Product $x) => ! $x->tracksStock()) ? '—' : ($r->isSoldOut() ? 'Esaurito' : (string) $v->sum('stock_qty'));
                }

                return $r->tracksStock() ? ($r->isSoldOut() ? 'Esaurito' : (string) $r->stock_qty) : '—';
            })->badge()->color(fn (Product $r) => $r->hasVariants() ? ($r->isSoldOut() ? 'danger' : 'gray') : (! $r->tracksStock() ? 'gray' : ($r->isSoldOut() ? 'danger' : ($r->isLowStock(self::LOW_STOCK) ? 'warning' : 'success')))),
            TextColumn::make('pv')->label('PV')->state(fn (Product $r) => $r->pvUnit())->toggleable(isToggledHiddenByDefault: true)->visible(fn (): bool => LegacyPlan::visible()),
            TextColumn::make('cv')->label('CV')->state(fn (Product $r) => $r->cvUnit())->toggleable(isToggledHiddenByDefault: true)->visible(fn (): bool => LegacyPlan::visible()),
            TextColumn::make('vap_value')->label('V.A.P. (commissione)')->state(function (Product $r): string {
                $v = $r->vapSetting();

                return $v === null ? 'piano' : ($v[0] === Product::VAP_FIXED ? number_format((float) $v[1], 2, ',', '.').' €' : rtrim(rtrim(number_format((float) $v[1], 2, ',', '.'), '0'), ',').' %');
            })->toggleable(),
            TextColumn::make('customer_validity_months')->label('Clienti e durata')->state(fn (Product $r) => $r->customerCount().($r->customerCount() === 1 ? ' cliente' : ' clienti').' · '.$r->customerValidityMonths().($r->customerValidityMonths() === 1 ? ' mese' : ' mesi'))->toggleable(),
            TextColumn::make('kmoney_percent')->label('KMoney')->formatStateUsing(fn ($state) => (int) $state > 0 ? $state.'%' : '—')->badge()->color(fn ($state) => (int) $state > 0 ? 'success' : 'gray')->toggleable(),
            IconColumn::make('featured')->label('In evidenza')->boolean()->toggleable(),
            IconColumn::make('is_kit')->label('Kit')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ToggleColumn::make('active')->label('Pubblicato')->disabled(fn () => ! auth()->user()?->can('catalog.manage')),
        ])->filters([
            SelectFilter::make('category_id')->label('Categoria')->options(fn () => ProductCategory::treeOptions())
                ->query(fn (Builder $q, array $data) => filled($data['value'] ?? null) ? $q->whereIn('category_id', ProductCategory::withChildIds([$data['value']])) : $q),
            TernaryFilter::make('active')->label('Pubblicazione')->trueLabel('Pubblicati')->falseLabel('Nascosti')->placeholder('Tutti'),
            Filter::make('low_stock')->label('Giacenza bassa o esaurita')->query(fn (Builder $q) => $q->whereNull('parent_id')->where(fn (Builder $w) => $w->where(fn (Builder $x) => $x->whereNotNull('stock_qty')->where('stock_qty', '<=', self::LOW_STOCK))->orWhereHas('variants', fn (Builder $v) => $v->whereNotNull('stock_qty')->where('stock_qty', '<=', self::LOW_STOCK)))),
        ])->recordActions([
            EditAction::make()->label('Modifica')->iconButton()->tooltip('Modifica'),
            ReplicateAction::make()->label('Duplica')->iconButton()->tooltip('Duplica')->authorize('catalog.manage')
                ->excludeAttributes(['slug', 'stock_qty'])
                ->beforeReplicaSaved(function (Model $replica): void {
                    $replica->sku = Str::limit($replica->sku, 40, '').'-COPIA'.Str::upper(Str::random(3));
                    $replica->name = $replica->name.' (copia)';
                    $replica->active = false;
                })
                ->after(function (Model $replica, Model $record): void {
                    foreach ($record->variants as $v) {
                        $copy = $v->replicate(['slug', 'sku', 'parent_id']);
                        $copy->parent_id = $replica->id;
                        $copy->sku = Str::limit($v->sku, 40, '').'-COPIA'.Str::upper(Str::random(3));
                        $copy->active = false;
                        $copy->stock_qty = $v->tracksStock() ? 0 : null;
                        $copy->save();
                    }
                })
                ->successNotificationTitle('Prodotto duplicato: la copia è nascosta, completala e pubblicala.'),
            DeleteAction::make()->label('Elimina')->iconButton()->tooltip('Elimina')->visible(fn (Product $r) => auth()->user()?->can('catalog.manage') && $r->isDeletable())
                ->modalDescription('Il prodotto non è mai stato venduto: si può eliminare. Altrimenti nascondilo.'),
        ])->toolbarActions([
            BulkActionGroup::make([
                BulkAction::make('publish')->label('Pubblica')->icon('heroicon-o-eye')->authorize('catalog.manage')->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
                        $records->each->update(['active' => true]);
                        Notification::make()->title($records->count().' prodotti pubblicati')->success()->send();
                    }),
                BulkAction::make('hide')->label('Nascondi')->icon('heroicon-o-eye-slash')->authorize('catalog.manage')->requiresConfirmation()->deselectRecordsAfterCompletion()
                    ->modalDescription('I prodotti spariscono dal negozio e non si potranno acquistare; lo storico ordini resta.')
                    ->action(function (Collection $records): void {
                        $records->each->update(['active' => false]);
                        Notification::make()->title($records->count().' prodotti nascosti')->success()->send();
                    }),
                BulkAction::make('category')->label('Sposta in categoria')->icon('heroicon-o-folder')->authorize('catalog.manage')->deselectRecordsAfterCompletion()
                    ->schema([Select::make('category_id')->label('Categoria')->options(fn () => ProductCategory::treeOptions())->placeholder('Nessuna categoria')])
                    ->action(function (Collection $records, array $data): void {
                        $records->each->update(['category_id' => $data['category_id'] ?: null]);
                        Notification::make()->title('Categoria aggiornata')->success()->send();
                    }),
            ]),
        ]);
    }

    /** In elenco solo i prodotti principali: le varianti si gestiscono dentro il prodotto. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('parent_id')->with('variants');
    }

    public static function getPages(): array
    {
        return ['index' => ListProducts::route('/'), 'create' => CreateProduct::route('/create'), 'edit' => EditProduct::route('/{record}/edit')];
    }
}
