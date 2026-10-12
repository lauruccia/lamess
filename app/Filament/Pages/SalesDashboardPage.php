<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Features\Feature;
use App\Domain\Kpi\SalesDashboard;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Cruscotto vendite (preventivi, ordini, ricavo, prodotti, clienti, incaricati…) — sola lettura, per chi ha il permesso KPI. */
class SalesDashboardPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Vendite';

    protected static ?string $navigationLabel = 'Cruscotto vendite';

    protected static ?string $title = 'Cruscotto vendite';

    protected static ?string $slug = 'cruscotto-vendite';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.sales-dashboard';

    /** Durata della finestra in giorni (0 = tutto lo storico). */
    public int $days = 90;

    /** Quante finestre indietro rispetto a oggi (0 = fino a oggi). */
    public int $offset = 0;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('kpi.view') && Feature::enabled('backoffice.page.cruscotto_vendite');
    }

    public function updatedDays(): void
    {
        if (! array_key_exists($this->days, SalesDashboard::RANGES)) {
            $this->days = 90;
        }
        $this->offset = 0;
    }

    public function previous(): void
    {
        if ($this->days > 0) {
            $this->offset++;
        }
    }

    public function next(): void
    {
        $this->offset = max(0, $this->offset - 1);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $days = array_key_exists($this->days, SalesDashboard::RANGES) ? $this->days : 90;

        return ['d' => app(SalesDashboard::class)->build($days, $days > 0 ? $this->offset : 0), 'ranges' => SalesDashboard::RANGES];
    }
}
