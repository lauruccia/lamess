<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Features\Feature;
use App\Domain\Plan\IncaricatiSimulator;
use App\Domain\Plan\RankComparison;
use App\Models\Consultant;
use App\Models\Period;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use UnitEnum;

/**
 * Simulazione «cosa succede se» sull'albero e sulle qualifiche degli incaricati — solo admin con permesso e super_admin.
 * SOLA LETTURA: lavora su una copia in memoria dei dati del periodo, non scrive nulla nel database.
 */
class IncaricatiSimulationPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Compensi';

    protected static ?string $navigationLabel = 'Simulazione incaricati';

    protected static ?string $title = 'Simulazione: albero e qualifiche degli incaricati';

    protected static ?string $slug = 'simulazione-incaricati';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.pages.incaricati-simulation';

    public ?string $periodCode = null;

    /** cumulative = clienti che hanno acquistato almeno una volta fino a fine mese · period = solo acquisti del mese */
    public string $clientCount = 'cumulative';

    /** Gli agenti simulati entrano in rete nel mese (generano il Bonus di Struttura se portano 3 clienti entro 30 giorni). */
    public bool $newAgents = true;

    /** @var list<array{type:string,target:string,count:string,amount:string,rank:string,sponsor:string}> */
    public array $changes = [];

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public ?string $error = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('plan.compare') && Feature::enabled('backoffice.page.simulazione_incaricati');
    }

    public function mount(): void
    {
        $this->periodCode = Period::query()->orderByDesc('code')->value('code');
        $this->changes = [self::blank('clients')];
    }

    /** @return array{type:string,target:string,count:string,amount:string,rank:string,sponsor:string} */
    private static function blank(string $type): array
    {
        return ['type' => $type, 'target' => '', 'count' => '1', 'amount' => '', 'rank' => 'PEARL', 'sponsor' => ''];
    }

    public function addChange(string $type = 'clients'): void
    {
        $this->changes[] = self::blank($type);
    }

    public function removeChange(int $index): void
    {
        unset($this->changes[$index]);
        $this->changes = array_values($this->changes);
        if ($this->changes === []) {
            $this->changes = [self::blank('clients')];
        }
    }

    public function resetSimulation(): void
    {
        $this->changes = [self::blank('clients')];
        $this->result = null;
        $this->error = null;
    }

    public function simulate(): void
    {
        $this->result = null;
        $this->error = null;
        $version = app(RankComparison::class)->incaricatiPlan();
        $period = $this->periodCode ? Period::where('code', $this->periodCode)->first() : null;
        if ($version === null || $period === null) {
            $this->error = 'Servono la versione del piano Incaricati e un periodo.';

            return;
        }
        try {
            $this->result = app(IncaricatiSimulator::class)->run(
                $period, $version->plan(), $this->resolvedChanges(), $this->clientCount !== 'period', $this->newAgents,
            );
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** @return list<array<string, mixed>> */
    private function resolvedChanges(): array
    {
        $byLabel = $this->labelIndex();
        $out = [];
        foreach ($this->changes as $i => $c) {
            $n = $i + 1;
            $row = ['type' => $c['type'], 'target' => $this->resolve($byLabel, $c['target'], $n), 'count' => $c['count'], 'amount' => $c['amount'], 'rank' => $c['rank']];
            if ($c['type'] === 'move') {
                $row['sponsor'] = $this->resolve($byLabel, $c['sponsor'], $n, 'il nuovo sponsor');
            }
            $out[] = $row;
        }

        return $out;
    }

    /** @return array<string, string> testo normalizzato (etichetta o codice) => id */
    private function labelIndex(): array
    {
        $idx = [];
        foreach ($this->consultantOptions() as $id => $label) {
            $idx[mb_strtolower($label)] = (string) $id;
            $idx[mb_strtolower(trim(explode(' — ', $label)[0]))] ??= (string) $id;
        }

        return $idx;
    }

    /** @param array<string, string> $idx */
    private function resolve(array $idx, string $text, int $n, string $what = "l'incaricato"): string
    {
        $key = mb_strtolower(trim($text));
        if ($key === '' || ! isset($idx[$key])) {
            throw new InvalidArgumentException("Modifica {$n}: scegli {$what} dall'elenco (codice o «CODICE — Nome»).");
        }

        return $idx[$key];
    }

    /** @return array<int, string> id => «CODICE — Nome» (esclusi i profili non ancora attivati) */
    private function consultantOptions(): array
    {
        return Consultant::query()->where('status', '!=', 'pending')->orderBy('code')->get(['id', 'code', 'name'])
            ->mapWithKeys(static fn (Consultant $c) => [$c->id => "{$c->code} — {$c->name}"])->all();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $version = app(RankComparison::class)->incaricatiPlan();
        $plan = $version?->plan();

        return [
            'periods' => Period::query()->orderByDesc('code')->pluck('code')->all(),
            'version' => $version,
            'options' => $version ? array_values($this->consultantOptions()) : [],
            'ranks' => $plan instanceof PlanConfig ? array_map(static fn ($r) => ['code' => $r->code, 'name' => $r->name], $plan->ranks) : [],
            'minAmount' => $plan?->kosmos?->clientMinAmount,
        ];
    }
}
