<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Features\Feature;
use App\Domain\Settings\SystemSettings;
use BackedEnum;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Pagamenti e spedizioni — per il super_admin e per chi gestisce il catalogo (admin finanza).
 * Stesso meccanismo delle impostazioni di sistema (valori validati, ogni modifica nel log di audit), ma solo le voci del negozio.
 * Le chiavi di Stripe e di KMoney restano nel file .env: non passano mai da qui.
 */
class ShopSettingsPage extends SystemSettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Pagamenti e spedizioni';

    protected static ?string $title = 'Pagamenti e spedizioni';

    protected static ?string $slug = 'negozio-pagamenti';

    protected static ?int $navigationSort = 18;

    private const GROUPS = ['Metodi di pagamento', 'Ordini e fatture', 'Abbonamenti e spedizione'];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return ($user?->isSuperAdmin() === true || (bool) $user?->can('catalog.manage')) && Feature::enabled('backoffice.page.negozio_pagamenti');
    }

    protected function definitions(): array
    {
        return array_filter(SystemSettings::DEFINITIONS, fn (array $def) => in_array($def['group'], self::GROUPS, true));
    }

    protected function showSystemStatus(): bool
    {
        return false;
    }

    protected function intro(): array
    {
        return [
            Section::make('Dove si imposta il resto')->columns(1)->schema([
                Text::make('Costo di spedizione per paese: Vendite → Tariffe di spedizione.'),
                Text::make('Spedizione gratuita sopra una soglia di spesa: Vendite → Sconti, tipo «Spedizione gratuita» con spesa minima (automatico).'),
                Text::make('Chiavi di Stripe e di KMoney: file .env sul server (non si cambiano da qui, per sicurezza).'),
            ]),
        ];
    }
}
