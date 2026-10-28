<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Features\Feature;
use App\Domain\Payouts\StatementService;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Filament\Support\ConsultantList;
use App\Models\CalculationRun;
use App\Models\Period;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Compensi del mese»: quanto sta maturando ogni incaricato nel mese scelto (di default quello in corso).
 * Mese aperto = stima dell'ultimo calcolo (si aggiorna a ogni ordine, pagamento o movimento); mese chiuso = importi definitivi.
 * Sola lettura, per chi può vedere liquidazioni e pagamenti.
 */
class CompensiMesePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $navigationLabel = 'Compensi del mese';

    protected static ?string $title = 'Compensi del mese';

    protected static ?string $slug = 'compensi-mese';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.compensi-mese';

    #[Url(as: 'mese')]
    public ?string $periodCode = null;

    #[Url(as: 'cerca')]
    public string $search = '';

    #[Url(as: 'solo-importi')]
    public bool $onlyNonZero = true;

    #[Url(as: 'ordine')]
    public string $sort = 'total';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('payouts.view') && Feature::enabled('backoffice.page.compensi_mese');
    }

    public function period(): ?Period
    {
        $q = Period::query()->orderByDesc('code');

        return $this->periodCode ? ((clone $q)->where('code', $this->periodCode)->first() ?? $q->first()) : $q->first();
    }

    /** @return Collection<int, Period> */
    public function periods(): Collection
    {
        return Period::query()->orderByDesc('code')->limit(24)->get(['id', 'code', 'status']);
    }

    private function runOf(Period $p): ?CalculationRun
    {
        $id = $p->official_run_id ?? CalculationRun::query()->where('period_id', $p->id)->where('status', 'completed')->orderByDesc('id')->value('id');

        return $id ? CalculationRun::find($id) : null;
    }

    /**
     * @return array{period: ?Period, run: ?CalculationRun, closed: bool, rows: list<array<string,mixed>>, totals: array<string,float>, types: array<string,string>}
     */
    public function data(): array
    {
        $period = $this->period();
        $empty = ['period' => $period, 'run' => null, 'closed' => false, 'rows' => [], 'totals' => ['accrued' => 0.0, 'held' => 0.0, 'structure' => 0.0, 'total' => 0.0, 'open' => 0.0], 'types' => []];
        if ($period === null || ($run = $this->runOf($period)) === null) {
            return $empty;
        }

        $labels = StatementService::LABELS;
        $lines = DB::table('commission_lines')->where('run_id', $run->id)->where('bonus_type', '!=', 'structure')
            ->groupBy('beneficiary_id', 'bonus_type')
            ->selectRaw("beneficiary_id, bonus_type, sum(case when engine_status = 'accrued' then amount else 0 end) as accrued, sum(case when engine_status = 'held' then amount else 0 end) as held")
            ->get();
        $by = [];
        $types = [];
        foreach ($lines as $l) {
            $by[$l->beneficiary_id]['types'][$l->bonus_type] = (float) $l->accrued;
            $by[$l->beneficiary_id]['accrued'] = ($by[$l->beneficiary_id]['accrued'] ?? 0.0) + (float) $l->accrued;
            $by[$l->beneficiary_id]['held'] = ($by[$l->beneficiary_id]['held'] ?? 0.0) + (float) $l->held;
            $types[$l->bonus_type] = $labels[$l->bonus_type] ?? (string) $l->bonus_type;
        }

        $structure = DB::table('structure_bonuses')->where('period_id', $period->id)->where('status', '!=', 'cancelled')->where('engine_status', 'accrued')
            ->groupBy('beneficiary_id')->selectRaw('beneficiary_id, sum(amount) as amount')->pluck('amount', 'beneficiary_id');

        $ids = array_unique(array_merge(array_keys($by), $structure->keys()->all()));
        $consultants = DB::table('consultants')->whereIn('id', $ids)->get(['id', 'code', 'name', 'iban', 'kyc_status'])->keyBy('id');

        $rank = DB::table('consultant_period_stats')->where('run_id', $run->id)->whereIn('consultant_id', $ids)->pluck('effective_rank', 'consultant_id');
        $prev = Period::query()->where('code', '<', $period->code)->orderByDesc('code')->first();
        $prevRun = $prev ? $this->runOf($prev) : null;
        $prevRank = $prevRun ? DB::table('consultant_period_stats')->where('run_id', $prevRun->id)->whereIn('consultant_id', $ids)->pluck('effective_rank', 'consultant_id') : collect();

        $open = DB::table('settlements')->where('status', 'open')->whereNull('payout_id')->whereIn('consultant_id', $ids)->groupBy('consultant_id')->selectRaw('consultant_id, sum(accrued) as s')->pluck('s', 'consultant_id');

        $names = app(ConsultantList::class);
        $strip = static fn (?string $n): string => $n === null ? '—' : preg_replace('/^Incaricato /', '', $names->rankName($n));
        $needle = mb_strtolower(trim($this->search));
        $rows = [];
        foreach ($ids as $id) {
            $c = $consultants[$id] ?? null;
            if ($c === null) {
                continue;
            }
            if ($needle !== '' && ! str_contains(mb_strtolower($c->name.' '.$c->code), $needle)) {
                continue;
            }
            $accrued = (float) ($by[$id]['accrued'] ?? 0);
            $struct = (float) ($structure[$id] ?? 0);
            $total = $accrued + $struct;
            if ($this->onlyNonZero && abs($total) < 0.005 && abs((float) ($by[$id]['held'] ?? 0)) < 0.005) {
                continue;
            }
            $r = $rank[$id] ?? null;
            $pr = $prevRank[$id] ?? null;
            $rows[] = [
                'id' => (int) $id, 'code' => $c->code, 'name' => $c->name, 'url' => ConsultantResource::getUrl('view', ['record' => $id]),
                'rank' => $strip($r), 'rank_from' => ($pr !== null && $r !== null && $pr !== $r) ? $strip($pr) : null,
                'types' => $by[$id]['types'] ?? [], 'structure' => $struct, 'accrued' => $accrued, 'held' => (float) ($by[$id]['held'] ?? 0),
                'total' => $total, 'open' => (float) ($open[$id] ?? 0),
                'payable' => $c->iban !== null && $c->iban !== '' && $c->kyc_status === 'verified',
                'statement' => $period->status === Period::CLOSED ? url("/admin-files/statement/{$period->code}/{$id}") : null,
            ];
        }
        usort($rows, fn (array $a, array $b): int => match ($this->sort) {
            'name' => strcasecmp($a['name'], $b['name']),
            'held' => $b['held'] <=> $a['held'],
            default => $b['total'] <=> $a['total'],
        });

        $totals = ['accrued' => array_sum(array_column($rows, 'accrued')), 'held' => array_sum(array_column($rows, 'held')), 'structure' => array_sum(array_column($rows, 'structure')),
            'total' => array_sum(array_column($rows, 'total')), 'open' => array_sum(array_column($rows, 'open'))];

        return ['period' => $period, 'run' => $run, 'closed' => $period->status === Period::CLOSED, 'rows' => $rows, 'totals' => $totals, 'types' => $types];
    }

    public function exportCsv(): StreamedResponse
    {
        $d = $this->data();
        $code = $d['period']?->code ?? 'mese';

        return response()->streamDownload(function () use ($d): void {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            $head = ['Codice', 'Incaricato', 'Qualifica'];
            foreach ($d['types'] as $label) {
                $head[] = $label;
            }
            array_push($head, 'Bonus di Struttura', 'Totale maturato', 'Trattenuto', 'Saldo non ancora pagato', 'Pagabile');
            fputcsv($f, $head, ';');
            foreach ($d['rows'] as $r) {
                $row = [$r['code'], $r['name'], $r['rank']];
                foreach (array_keys($d['types']) as $t) {
                    $row[] = number_format((float) ($r['types'][$t] ?? 0), 2, ',', '');
                }
                array_push($row, number_format($r['structure'], 2, ',', ''), number_format($r['total'], 2, ',', ''), number_format($r['held'], 2, ',', ''), number_format($r['open'], 2, ',', ''), $r['payable'] ? 'sì' : 'no');
                fputcsv($f, $row, ';');
            }
            fclose($f);
        }, "compensi-{$code}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
