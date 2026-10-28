<?php

declare(strict_types=1);

namespace App\Filament\Resources\BundleRules;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\BundleRules\Pages\CreateBundleRule;
use App\Filament\Resources\BundleRules\Pages\EditBundleRule;
use App\Filament\Resources\BundleRules\Pages\ListBundleRules;
use App\Filament\Support\FormSection;
use App\Models\BundleRule;
use App\Models\Product;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Regole «compra N → ricevi M omaggi» (RF-042). Gli omaggi hanno PV zero. */
class BundleRuleResource extends Resource
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

    protected static ?string $model = BundleRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'regola omaggio';

    protected static ?string $pluralModelLabel = 'Omaggi (bundle)';

    protected static ?int $navigationSort = 11;

    public static function form(Schema $schema): Schema
    {
        $products = fn () => Product::whereDoesntHave('variants')->orderBy('name')->pluck('name', 'id');

        return $schema->components([
            FormSection::make('Quando scatta', 'Quale prodotto, e quanti pezzi, fanno partire l\'omaggio.', [
                Select::make('trigger_product_id')->label('Prodotto che attiva')->options($products)->required()->searchable(),
                TextInput::make('trigger_qty')->label('Quantità da acquistare (N)')->numeric()->minValue(1)->required(),
                Toggle::make('repeatable')->label('Si ripete a ogni multiplo di N')->default(true),
            ]),
            FormSection::make('Cosa si regala', 'Il prodotto omaggio e quanti pezzi.', [
                Select::make('gift_product_id')->label('Prodotto omaggio')->options($products)->required()->searchable(),
                TextInput::make('gift_qty')->label('Quantità omaggio (M)')->numeric()->minValue(1)->default(1)->required(),
            ]),
            FormSection::make('Validità', 'Fuori da queste date, o se disattivata, la regola non si applica.', [
                DatePicker::make('active_from')->label('Valida dal')->native(false)->displayFormat('d/m/Y'),
                DatePicker::make('active_to')->label('Valida fino al')->native(false)->displayFormat('d/m/Y'),
                Toggle::make('active')->label('Attiva')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('trigger.name')->label('Acquisti'),
            TextColumn::make('trigger_qty')->label('N'),
            TextColumn::make('gift.name')->label('Omaggio'),
            TextColumn::make('gift_qty')->label('M'),
            IconColumn::make('repeatable')->label('Ripetibile')->boolean(),
            IconColumn::make('active')->label('Attiva')->boolean(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListBundleRules::route('/'), 'create' => CreateBundleRule::route('/create'), 'edit' => EditBundleRule::route('/{record}/edit')];
    }
}
