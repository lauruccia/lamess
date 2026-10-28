<?php

declare(strict_types=1);

namespace App\Filament\Resources\ShippingRates;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\ShippingRates\Pages\CreateShippingRate;
use App\Filament\Resources\ShippingRates\Pages\EditShippingRate;
use App\Filament\Resources\ShippingRates\Pages\ListShippingRates;
use App\Filament\Support\FormSection;
use App\Models\ShippingRate;
use BackedEnum;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Tariffa di spedizione per paese (RF-046). Non genera PV (OPEN-18). */
class ShippingRateResource extends Resource
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

    protected static ?string $model = ShippingRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $modelLabel = 'tariffa di spedizione';

    protected static ?string $pluralModelLabel = 'Spedizioni';

    protected static ?int $navigationSort = 12;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FormSection::make('Dove', 'Paese di destinazione ed eventuale nome della tariffa.', [
                TextInput::make('name')->label('Nome (facoltativo)')->maxLength(80)->placeholder('Es. Corriere espresso'),
                TextInput::make('country_code')->label('Paese (codice 2 lettere, * = resto del mondo)')->required()->maxLength(2)->helperText('Puoi inserire più tariffe per lo stesso paese con fasce di peso diverse.')->dehydrateStateUsing(fn ($state) => strtoupper((string) $state)),
            ]),
            FormSection::make('Costo', 'Si inserisce IVA inclusa: l\'importo IVA esclusa si calcola da solo.', [
                TextInput::make('amount_gross')->label('Costo di spedizione (IVA inclusa)')->prefix('€')->numeric()->required()->minValue(0)->dehydrated(false)->live(onBlur: true)
                    ->afterStateHydrated(fn (TextInput $c, $record) => $c->state($record ? number_format((float) $record->amount_net * (1 + (float) $record->vat_rate / 100), 2, '.', '') : null))
                    ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('amount_net', round((float) $state / (1 + (float) ($get('vat_rate') ?: 22) / 100), 2)))
                    ->helperText('È la tariffa generale: si può cambiare per singolo prodotto dalla sua scheda.'),
                Hidden::make('amount_net')->required(),
                TextInput::make('vat_rate')->label('IVA (%)')->numeric()->required()->default(22)->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set, $state) => $set('amount_net', round((float) $get('amount_gross') / (1 + (float) ($state ?: 22) / 100), 2))),
            ]),
            FormSection::make('Fascia di peso', 'La tariffa vale per i carrelli in questa fascia.', [
                TextInput::make('min_weight_g')->label('Peso minimo (g)')->numeric()->integer()->minValue(0)->default(0)->required(),
                TextInput::make('max_weight_g')->label('Peso massimo (g)')->numeric()->integer()->minValue(0)->gte('min_weight_g')->placeholder('nessun limite')->helperText('Il peso del carrello è la somma dei pesi dei prodotti (campo «Peso» nella scheda prodotto).'),
                Toggle::make('active')->label('Attiva')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('country_code')->label('Paese')->sortable(),
            TextColumn::make('name')->label('Nome')->placeholder('—'),
            TextColumn::make('min_weight_g')->label('Da (g)'),
            TextColumn::make('max_weight_g')->label('A (g)')->placeholder('∞'),
            TextColumn::make('amount_net')->label('Netto €')->numeric(2),
            TextColumn::make('amount_gross')->label('IVA incl. €')->state(fn ($record) => number_format((float) $record->amount_net * (1 + (float) $record->vat_rate / 100), 2, ',', '.')),
            TextColumn::make('vat_rate')->label('IVA %'),
            IconColumn::make('active')->label('Attiva')->boolean(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListShippingRates::route('/'), 'create' => CreateShippingRate::route('/create'), 'edit' => EditShippingRate::route('/{record}/edit')];
    }
}
