<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Kpi\KpiService;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Cruscotto';

    /** Griglia a 12 colonne: indicatori + stato automatismi affiancati, poi i due grafici affiancati. */
    public function getColumns(): int | array
    {
        return ['default' => 1, 'lg' => 12];
    }

    /** Il filtro «Periodo» sale nella riga del titolo (view dedicata) invece di occupare una riga intera. */
    public function content(Schema $schema): Schema
    {
        return $schema->components([$this->getWidgetsContentComponent()]);
    }

    public function getHeader(): ?\Illuminate\Contracts\View\View
    {
        return view('filament.pages.dashboard-header', ['heading' => $this->getHeading(), 'filters' => $this->getFiltersForm()]);
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('period')->hiddenLabel()->placeholder('Seleziona periodo')
                ->options(fn () => app(KpiService::class)->periodOptions())
                ->default(fn () => app(KpiService::class)->latestPeriodCode())
                ->native(false),
        ]);
    }
}
