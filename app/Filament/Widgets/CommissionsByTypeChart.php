<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Features\Feature;
use App\Domain\Kpi\KpiService;
use App\Support\Brand;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class CommissionsByTypeChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = ['default' => 'full', 'lg' => 5];

    protected ?string $maxHeight = '280px';

    protected ?string $heading = 'Compensi per tipo di bonus';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('kpi.view') && Feature::enabled('backoffice.widget.commissions_by_type');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $svc = app(KpiService::class);
        $c = $svc->snapshot($this->pageFilters['period'] ?? $svc->latestPeriodCode())['commissions'];

        return [
            'datasets' => [['data' => array_map('floatval', array_values($c)), 'backgroundColor' => Brand::chart(count($c))]],
            'labels' => array_keys($c),
        ];
    }
}
