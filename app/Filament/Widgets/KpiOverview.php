<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Plan\LegacyPlan;
use App\Domain\Features\Feature;
use App\Domain\Kpi\KpiService;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters;

    /** Caricato insieme alla pagina: niente richiesta Livewire separata per ogni widget. */
    protected static bool $isLazy = false;

    /** Filament aggiorna i widget ogni 5 secondi per default: troppe richieste PHP per l'hosting condiviso. */
    protected ?string $pollingInterval = null;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 8];

    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'xl' => 4];

    protected ?string $heading = 'Indicatori del periodo';

    public static function canView(): bool
    {
        return (bool) auth()->user()?->can('kpi.view') && Feature::enabled('backoffice.widget.kpi');
    }

    protected function getStats(): array
    {
        $k = app(KpiService::class)->snapshot($this->pageFilters['period'] ?? app(KpiService::class)->latestPeriodCode());
        $st = $k['consultants_by_status'];
        $kind = $k['run_type'] === 'official' ? 'calcolo ufficiale' : ($k['run_type'] ? 'stima live' : 'nessun calcolo');
        $eur = fn (string $v) => '€ '.number_format((float) $v, 2, ',', '.');

        return array_values(array_filter([
            Stat::make('Fatturato (CV)', $eur($k['cv']))->description("{$k['movements']} movimenti confermati"),
            LegacyPlan::visible() ? Stat::make('Punti volume (PV)', number_format((float) $k['pv'], 2, ',', '.'))->description($k['pending_payment'].' in attesa di pagamento') : null,
            Stat::make('Compensi maturati', $eur($k['commissions_total']))->description($kind),
            Stat::make('Payout ratio', $k['payout_ratio'] === null ? '—' : number_format($k['payout_ratio'], 2, ',', '.').' %')->description('compensi / CV'),
            Stat::make('Incaricati attivi', (string) $k['active'])->description(($st['active'] ?? 0).' in organico · '.($st['terminated'] ?? 0).' cessati'),
            Stat::make('Nuovi incaricati', (string) $k['new_consultants'])->description('nel periodo'),
            Stat::make('Nuovi FAN', (string) $k['new_fan'])->description('nel periodo'),
            Stat::make('FAN totali', (string) $k['fan_total'])->description('clienti registrati'),
            Stat::make('Contratti da accettare', (string) $k['ivd_to_review'])->color($k['ivd_to_review'] ? 'warning' : 'success'),
            Stat::make('Documenti KYC da verificare', (string) $k['kyc_pending'])->color($k['kyc_pending'] ? 'warning' : 'success'),
            Stat::make('Canoni da incassare', (string) $k['fees_due'])->description($eur($k['fees_due_amount']))->color($k['fees_due'] ? 'warning' : 'success'),
            Stat::make('Compensi trattenuti', $eur($k['held_total']))->description('in attesa di sblocco'),
        ]));
    }
}
