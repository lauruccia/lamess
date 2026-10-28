<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Analytics\SiteStats;
use App\Domain\Analytics\Tracker;
use App\Domain\Features\Feature;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Statistiche del sito: utenti online, pagine, ricerche, provenienza e geografia, per il sito pubblico e per il back-office. Sola lettura. */
class SiteStatsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Sistema';

    protected static ?string $navigationLabel = 'Statistiche sito';

    protected static ?string $title = 'Statistiche sito';

    protected static ?string $slug = 'statistiche-sito';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.site-stats';

    /** front = sito pubblico e area riservata; back = back-office. */
    public string $area = 'front';

    public int $days = 30;

    /** Nel sito pubblico: contare anche le visite dello staff (di solito no, sono prove). */
    public bool $includeStaff = false;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('kpi.view') && Feature::enabled('backoffice.page.statistiche_sito');
    }

    public function updatedDays(): void
    {
        if (! array_key_exists($this->days, SiteStats::RANGES)) {
            $this->days = 30;
        }
    }

    public function updatedArea(): void
    {
        if (! in_array($this->area, ['front', 'back'], true)) {
            $this->area = 'front';
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $ready = Tracker::ready();

        return [
            'ready' => $ready,
            'enabled' => Tracker::enabled(),
            'ranges' => SiteStats::RANGES,
            'retention' => max(30, (int) config('lamess.analytics.retention_days', 180)),
            'siteOpen' => (bool) config('site.open'),
            'd' => $ready ? app(SiteStats::class)->build($this->area, array_key_exists($this->days, SiteStats::RANGES) ? $this->days : 30, $this->includeStaff) : null,
        ];
    }
}
