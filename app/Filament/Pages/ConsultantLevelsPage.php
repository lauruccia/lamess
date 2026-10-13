<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Features\Feature;
use App\Domain\Network\LevelList;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Models\Consultant;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/** Elenco degli incaricati in ordine di ramo, con un rientro per ogni livello. Sola lettura. */
class ConsultantLevelsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBars3BottomLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $navigationLabel = 'Elenco per livelli';

    protected static ?string $title = 'Elenco incaricati per livelli';

    protected static ?string $slug = 'elenco-livelli';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.pages.consultant-levels';

    /** Incaricato da cui partire (vuoto = tutta la rete). */
    #[Url(as: 'da')]
    public ?int $rootId = null;

    /** Ultimo livello mostrato (vuoto = tutti). */
    #[Url(as: 'livelli')]
    public ?int $maxLevel = null;

    #[Url(as: 'stato')]
    public string $status = 'all';

    /** Periodo per la colonna «Hanno acquistato»: month | last_month | last_3_months | year | custom. */
    #[Url(as: 'acquisti')]
    public string $buyPeriod = 'month';

    /** Estremi (Y-m-d) del periodo personalizzato. */
    #[Url(as: 'dal')]
    public ?string $buyFrom = null;

    #[Url(as: 'al')]
    public ?string $buyTo = null;

    public string $rootSearch = '';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('network.view') && Feature::enabled('backoffice.page.elenco_livelli');
    }

    public function selectRoot(int $id): void
    {
        $this->rootId = Consultant::whereKey($id)->exists() ? $id : null;
        $this->rootSearch = '';
    }

    public function clearRoot(): void
    {
        $this->rootId = null;
        $this->rootSearch = '';
    }

    /** @return array{from: string, to: string, label: string} periodo scelto per contare i clienti che hanno acquistato */
    public function buyRange(): array
    {
        $today = CarbonImmutable::today();
        $custom = $this->customRange();

        return match (true) {
            $this->buyPeriod === 'last_month' => ['from' => $today->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $today->subMonthNoOverflow()->endOfMonth()->toDateString(), 'label' => 'mese scorso'],
            $this->buyPeriod === 'last_3_months' => ['from' => $today->subMonthsNoOverflow(2)->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString(), 'label' => 'ultimi 3 mesi'],
            $this->buyPeriod === 'year' => ['from' => $today->startOfYear()->toDateString(), 'to' => $today->endOfYear()->toDateString(), 'label' => 'anno '.$today->year],
            $this->buyPeriod === 'custom' && $custom !== null => $custom + ['label' => $custom['from'] === $custom['to'] ? $custom['from'] : $custom['from'].' → '.$custom['to']],
            default => ['from' => $today->startOfMonth()->toDateString(), 'to' => $today->endOfMonth()->toDateString(), 'label' => 'mese in corso'],
        };
    }

    /** @return array{from: string, to: string}|null null se le date non sono valide (si ricade sul mese in corso) */
    private function customRange(): ?array
    {
        foreach ([$this->buyFrom, $this->buyTo] as $d) {
            if ($d === null || $d === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || CarbonImmutable::createFromFormat('Y-m-d', $d) === false || CarbonImmutable::createFromFormat('Y-m-d', $d)->toDateString() !== $d) {
                return null;
            }
        }

        return $this->buyFrom <= $this->buyTo ? ['from' => $this->buyFrom, 'to' => $this->buyTo] : ['from' => $this->buyTo, 'to' => $this->buyFrom];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $maxLevel = $this->maxLevel !== null && $this->maxLevel >= 0 ? $this->maxLevel : null;
        $status = in_array($this->status, ['all', 'active', 'terminated', 'not_terminated'], true) ? $this->status : 'all';
        $search = trim($this->rootSearch);
        $range = $this->buyRange();

        return [
            'list' => app(LevelList::class)->rows($this->rootId, $maxLevel, $status, [$range['from'], $range['to']]),
            'buyLabel' => $range['label'],
            'root' => $this->rootId ? Consultant::find($this->rootId, ['id', 'code', 'name']) : null,
            'suggestions' => $search === '' ? collect() : Consultant::query()
                ->where(fn ($w) => $w->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                ->orderBy('name')->limit(8)->get(['id', 'code', 'name']),
            'limit' => LevelList::LIMIT,
            'url' => fn (int $id): string => ConsultantResource::getUrl('view', ['record' => $id]),
        ];
    }
}
