<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Features\Feature;
use App\Domain\Periods\PeriodAutomation;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Cache;

/** Dice se il cron del server («schedule:run») sta davvero girando: da lì partono canoni, fatture, abbonamenti e pagamenti. */
class SchedulerStatus extends StatsOverviewWidget
{
    /** Filament aggiorna i widget ogni 5 secondi per default: troppe richieste PHP per l'hosting condiviso. */
    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = ['default' => 'full', 'lg' => 4];

    protected int | array | null $columns = 1;

    protected ?string $heading = 'Operazioni automatiche';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('periods.view') && Feature::enabled('backoffice.widget.scheduler');
    }

    protected function getStats(): array
    {
        $last = Cache::get('lamess.scheduler.heartbeat');
        $at = $last ? CarbonImmutable::parse($last)->setTimezone('Europe/Rome') : null;
        $ok = $at !== null && $at->diffInMinutes(now()) <= 20;

        $auto = Cache::get(PeriodAutomation::CACHE_KEY);

        $placeholder = str_contains((string) config('lamess.seller.name'), 'da configurare') || trim((string) config('lamess.seller.name')) === '';

        $stats = [
            Stat::make('Chiusura mensile automatica', match (config('lamess.period_auto_close')) {
                'close' => 'Calcola e chiude', 'calculate' => 'Solo calcolo', default => 'Spenta',
            })->description($auto['message'] ?? 'Non ancora eseguita')->color(($auto['action'] ?? '') === 'blocked' ? 'danger' : 'gray'),
            Stat::make('Cron del server', $ok ? 'Attivo' : ($at ? 'Fermo' : 'Mai partito'))
                ->description($at ? 'Ultimo giro: '.$at->format('d/m/Y H:i') : 'Va impostato «schedule:run» ogni minuto in cPanel')
                ->color($ok ? 'success' : 'danger'),
        ];
        if ($placeholder) {
            $stats[] = Stat::make('Dati del venditore', 'Da completare')
                ->description('Fatture e statini mostrano ancora il testo provvisorio: Impostazioni → Venditore')
                ->color('warning');
        }

        return $stats;
    }
}
