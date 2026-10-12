<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Features\Feature;
use App\Domain\Plan\RankComparison;
use App\Models\Consultant;
use App\Models\Period;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Confronto qualifica precedente / piano Incaricati — solo admin con permesso e super_admin. Solo lettura. */
class RankComparisonPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $navigationLabel = 'Confronto qualifiche';

    protected static ?string $title = 'Confronto qualifiche: sistema precedente e piano Incaricati';

    protected static ?string $slug = 'confronto-qualifiche';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.rank-comparison';

    public ?string $periodCode = null;

    /** cumulative = clienti che hanno acquistato almeno una volta fino a fine mese · period = solo acquisti del mese */
    public string $clientCount = 'cumulative';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('plan.compare') && Feature::enabled('backoffice.page.confronto_qualifiche');
    }

    public function mount(): void
    {
        $this->periodCode = Period::query()->orderByDesc('code')->value('code');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $svc = app(RankComparison::class);
        $version = $svc->incaricatiPlan();
        $period = $this->periodCode ? Period::where('code', $this->periodCode)->first() : null;
        $rows = ($version && $period) ? $svc->compare($period, $version->plan(), $this->clientCount !== 'period') : [];

        return [
            'periods' => Period::query()->orderByDesc('code')->pluck('code')->all(),
            'version' => $version, 'rows' => $rows,
            'frozen' => Consultant::whereNotNull('previous_rank_code')->count(),
            'total' => Consultant::count(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('freeze')->label('Congela le qualifiche attuali come «precedenti»')->color('gray')->requiresConfirmation()
                ->modalDescription('Per chi non ha ancora una qualifica precedente, salva quella oggi registrata. Non modifica nient\'altro e non si può sovrascrivere da qui.')
                ->action(function (): void {
                    $n = app(RankComparison::class)->freezePrevious();
                    Notification::make()->title("Qualifiche precedenti salvate: {$n}")->success()->send();
                }),
        ];
    }
}
