<?php

declare(strict_types=1);

namespace App\Filament\Resources\Settlements\Pages;

use App\Filament\Resources\Settlements\SettlementResource;
use App\Filament\Pages\Base\SuperadminListRecords;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ListSettlements extends SuperadminListRecords
{
    protected static string $resource = SettlementResource::class;

    private static function eur(mixed $v): string
    {
        return number_format((float) $v, 2, ',', '.').' €';
    }

    /** Numeri dell'ultimo mese con liquidazioni e di ciò che resta da pagare in totale (mostrati nell'intestazione). */
    private function kpis(): ?array
    {
        $last = DB::table('settlements')->join('periods', 'periods.id', '=', 'settlements.period_id')
            ->selectRaw('periods.code as code, count(*) as n, sum(case when settlements.accrued <> 0 then 1 else 0 end) as with_amount, sum(settlements.accrued) as accrued, sum(settlements.held) as held')
            ->groupBy('periods.code')->orderByDesc('periods.code')->first();
        if ($last === null) {
            return null;
        }
        $open = (float) DB::table('settlements')->where('status', 'open')->whereNull('payout_id')->sum('accrued');

        return ['code' => $last->code, 'n' => $last->n, 'with_amount' => $last->with_amount, 'accrued' => self::eur($last->accrued), 'held' => self::eur($last->held), 'open' => self::eur($open), 'open_positive' => $open > 0];
    }

    /** Intestazione propria: titolo con i numeri chiave a destra e, sotto, le schede per stato. */
    public function getHeader(): ?View
    {
        return view('filament.pages.settlements-header', [
            'breadcrumbs' => $this->getBreadcrumbs(), 'heading' => $this->getHeading(), 'kpis' => $this->kpis(),
            'tabs' => $this->getCachedTabs(), 'active' => $this->activeTab,
        ]);
    }

    /** Le schede stanno nell'intestazione: sopra la tabella non si ripetono. */
    public function getTabsContentComponent(): Tabs
    {
        return Tabs::make()->key('resourceTabs')->hidden();
    }

    /** Schede per stato di avanzamento, con i conteggi (una sola query). */
    public function getTabs(): array
    {
        $n = DB::table('settlements')->selectRaw(
            "count(*) as total, sum(case when status = 'open' and payout_id is null and accrued <> 0 then 1 else 0 end) as todo, "
            ."sum(case when status = 'open' and payout_id is not null then 1 else 0 end) as in_payment, "
            ."sum(case when status = 'paid' then 1 else 0 end) as paid, "
            ."sum(case when held <> 0 then 1 else 0 end) as held, "
            .'sum(case when accrued < 0 then 1 else 0 end) as negative'
        )->first();
        $tab = static fn (string $label, mixed $count, ?callable $q = null): Tab => Tab::make($label)->badge((int) $count)
            ->modifyQueryUsing($q ?? static fn (Builder $query): Builder => $query);

        return [
            'all' => $tab('Tutte', $n->total),
            'todo' => $tab('Da pagare', $n->todo, fn (Builder $query) => $query->where('settlements.status', 'open')->whereNull('settlements.payout_id')->where('settlements.accrued', '<>', 0)),
            'in_payment' => $tab('In pagamento', $n->in_payment, fn (Builder $query) => $query->where('settlements.status', 'open')->whereNotNull('settlements.payout_id')),
            'paid' => $tab('Pagate', $n->paid, fn (Builder $query) => $query->where('settlements.status', 'paid')),
            'held' => $tab('Con trattenuti', $n->held, fn (Builder $query) => $query->where('settlements.held', '<>', 0)),
            'negative' => $tab('Negative', $n->negative, fn (Builder $query) => $query->where('settlements.accrued', '<', 0)),
        ];
    }
}
