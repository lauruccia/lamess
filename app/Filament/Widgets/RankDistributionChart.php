<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Features\Feature;
use App\Domain\Kpi\KpiService;
use App\Support\Brand;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class RankDistributionChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 7];

    protected ?string $maxHeight = '280px';

    protected ?string $heading = 'Incaricati per qualifica';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('kpi.view') && Feature::enabled('backoffice.widget.rank_distribution');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $svc = app(KpiService::class);
        $ranks = $svc->snapshot($this->pageFilters['period'] ?? $svc->latestPeriodCode())['ranks'];

        return [
            'datasets' => [['label' => 'Incaricati', 'data' => array_values($ranks), 'backgroundColor' => Brand::TERRACOTTA]],
            'labels' => array_keys($ranks),
        ];
    }
}
