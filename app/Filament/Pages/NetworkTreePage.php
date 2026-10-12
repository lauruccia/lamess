<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Features\Feature;
use App\Domain\Career\CareerProgress;
use App\Filament\Resources\Consultants\ConsultantResource;
use App\Models\CompPlanVersion;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/** Albero grafico della rete: nodi a scheda, rami, zoom, ricerca, scheda con «cosa manca al livello successivo». */
class NetworkTreePage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Rete';

    protected static ?string $navigationLabel = 'Albero della rete';

    protected static ?string $title = 'Albero della rete';

    protected static ?string $slug = 'albero-rete';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.pages.network-tree';

    /** Dal più basso al più alto: sabbia → terracotta → viola scuro. Il nome della qualifica è sempre scritto, il colore non è l'unico segnale. */
    public const RANK_COLORS = ['#B9B1A5', '#D9A27E', '#C2663A', '#A98625', '#6A8F58', '#4A7A9B', '#5B6FA8', '#8A5C8E', '#B04A6A', '#8C2F39', '#5A3A4F', '#2B2B2B'];

    /** Colore fisso per qualifica, quello della pietra: perla, cristallo, zaffiro, rubino, smeraldo, diamante. */
    public const RANK_COLOR_BY_CODE = [
        'CONSULTANT' => '#BBB4AF', 'PEARL' => '#C8B6A6', 'CRYSTAL' => '#6EC6E6', 'SAPPHIRE' => '#0F52BA',
        'RUBY' => '#D0103A', 'EMERALD' => '#1E9E5A', 'DIAMOND' => '#AAB7C4', 'BLUE_DIAMOND' => '#2E86DE',
    ];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('network.view') && Feature::enabled('backoffice.page.albero_rete');
    }

    /** @return array{nodes: list<array<string, mixed>>, ranks: list<array{code: string, name: string, color: string}>, meta: array{period: ?string, source: string}} */
    public function treeData(): array
    {
        $plan = CompPlanVersion::inForce()?->plan();
        $ranks = [];
        foreach ($plan?->ranks ?? [] as $i => $r) {
            $ranks[] = ['code' => $r->code, 'name' => $r->name, 'color' => self::RANK_COLOR_BY_CODE[strtoupper($r->code)] ?? self::RANK_COLORS[$i % count(self::RANK_COLORS)]];
        }
        $progress = app(CareerProgress::class);
        $prog = $progress->all();
        $clients = DB::table('customers')->whereNotNull('assigned_consultant_id')->groupBy('assigned_consultant_id')
            ->selectRaw('assigned_consultant_id as id, count(*) as n')->pluck('n', 'id');
        $team = DB::table('network_paths')->where('depth', '>', 0)->groupBy('ancestor_id')->selectRaw('ancestor_id as id, count(*) as n')->pluck('n', 'id');
        $entry = $ranks[0]['code'] ?? null;

        $nodes = [];
        foreach (DB::table('consultants')->orderBy('id')->get(['id', 'code', 'name', 'status', 'sponsor_id', 'since']) as $c) {
            $p = $prog[$c->id] ?? null;
            $nodes[] = [
                'id' => $c->id, 'parent' => $c->sponsor_id, 'code' => $c->code, 'name' => $c->name, 'status' => $c->status,
                'rank' => $p['rank']['code'] ?? $entry, 'active' => (bool) ($p['activity']['active'] ?? false), 'pr' => (float) ($p['activity']['points'] ?? 0),
                'next' => $p['next']['name'] ?? null, 'pct' => $p === null || $p['next'] === null ? 100 : $p['pr']['percent'], 'missing' => (float) ($p['pr']['missing'] ?? 0),
                'clients' => (int) ($clients[$c->id] ?? 0), 'team' => (int) ($team[$c->id] ?? 0),
                'since' => $c->since ? date('d/m/Y', strtotime((string) $c->since)) : '', 'url' => ConsultantResource::getUrl('view', ['record' => $c->id]),
            ];
        }

        return ['nodes' => $nodes, 'ranks' => $ranks, 'meta' => $progress->meta()];
    }

    /** Scheda «cosa manca per il livello successivo», caricata alla selezione di un nodo. */
    public function progressHtml(int $id): string
    {
        abort_unless(static::canAccess(), 403);
        $p = app(CareerProgress::class)->forConsultant($id);

        return $p === null ? '<p>Nessun dato di carriera per questo incaricato (non ancora nel calcolo).</p>' : view('partials.career-progress', ['p' => $p])->render();
    }
}
