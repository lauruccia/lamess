<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Models\AnalyticsHit;
use App\Models\AnalyticsVisit;
use App\Models\Consultant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Numeri della pagina «Statistiche sito». Sola lettura. «Sito» = pagine pubbliche e area riservata; «Back-office» = /admin.
 * Una visita è una sessione con pause sotto i 30 minuti; «utenti online» sono le visite con attività negli ultimi minuti.
 * Le aggregazioni usano solo SQL semplice, uguale su SQLite e MySQL.
 */
final class SiteStats
{
    /** giorni => etichetta */
    public const RANGES = [1 => 'Oggi', 7 => 'Ultimi 7 giorni', 30 => 'Ultimi 30 giorni', 90 => 'Ultimi 90 giorni', 180 => 'Ultimi 180 giorni'];

    private const TOP = 10;

    public function __construct(private readonly ?CarbonImmutable $fixedNow = null) {}

    private function now(): CarbonImmutable
    {
        return $this->fixedNow ?? CarbonImmutable::now();
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, prev_start: CarbonImmutable, prev_end: CarbonImmutable}
     */
    public function window(int $days): array
    {
        $now = $this->now();
        $tz = 'Europe/Rome';
        $days = array_key_exists($days, self::RANGES) ? $days : 30;
        $start = $now->setTimezone($tz)->startOfDay()->subDays($days - 1)->utc();
        $prevEnd = $start->subSecond();

        return ['start' => $start, 'end' => $now, 'prev_start' => $start->subDays($days), 'prev_end' => $prevEnd];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $area, int $days, bool $includeStaff): array
    {
        $area = $area === 'back' ? 'back' : 'front';
        $w = $this->window($days);
        $staff = $area === 'back' || $includeStaff;

        $visits = fn (CarbonImmutable $from, CarbonImmutable $to): Builder => $this->visits($area, $staff)->whereBetween('started_at', [$from, $to]);
        $hits = fn (CarbonImmutable $from, CarbonImmutable $to): Builder => $this->hits($area, $staff)->whereBetween('created_at', [$from, $to]);

        $cur = $this->totals($visits($w['start'], $w['end']), $hits($w['start'], $w['end']));
        $prev = $this->totals($visits($w['prev_start'], $w['prev_end']), $hits($w['prev_start'], $w['prev_end']));

        $base = $visits($w['start'], $w['end']);

        return [
            'area' => $area,
            'window' => $w,
            'tiles' => [
                'visits' => ['value' => $cur['visits'], 'delta' => $this->delta($cur['visits'], $prev['visits'])],
                'hits' => ['value' => $cur['hits'], 'delta' => $this->delta($cur['hits'], $prev['hits'])],
                'per_visit' => ['value' => $cur['visits'] > 0 ? round($cur['hits'] / $cur['visits'], 1) : 0.0, 'delta' => null],
                'bounce' => ['value' => $cur['visits'] > 0 ? round($cur['bounced'] * 100 / $cur['visits'], 1) : 0.0, 'delta' => null],
                'logged' => ['value' => $cur['logged'], 'delta' => $this->delta($cur['logged'], $prev['logged'])],
            ],
            'daily' => $this->daily($area, $staff, $w['start'], $w['end'], $days),
            'online' => $this->online(),
            'sources' => $this->groupVisits($base, 'source'),
            'mediums' => $this->groupVisits($base, 'medium'),
            'campaigns' => $this->groupVisits((clone $base)->whereNotNull('campaign'), 'campaign'),
            'referrers' => $this->groupVisits((clone $base)->whereNotNull('referrer_host'), 'referrer_host'),
            'referral_codes' => $this->groupVisits((clone $base)->whereNotNull('referral_code'), 'referral_code'),
            'landing' => $this->groupVisits($base, 'landing_path'),
            'countries' => $this->countries($base),
            'cities' => $this->cities($base),
            'devices' => $this->groupVisits($base, 'device'),
            'browsers' => $this->groupVisits($base, 'browser'),
            'systems' => $this->groupVisits($base, 'os'),
            'pages' => $this->topPages($hits($w['start'], $w['end'])),
            'searches' => $area === 'front' ? $this->searches($hits($w['start'], $w['end'])) : ['top' => [], 'empty' => [], 'total' => 0],
            'people' => $area === 'back' ? $this->backPeople($hits($w['start'], $w['end'])) : [],
            'recent' => $this->recent($base),
            'has_geo' => (clone $base)->whereNotNull('country')->exists(),
        ];
    }

    /** Visitatori in questo momento (sito e back-office insieme). @return list<array<string, mixed>> */
    public function online(): array
    {
        $since = $this->now()->subMinutes((int) config('lamess.analytics.online_minutes', 5));
        $rows = AnalyticsVisit::query()->where('last_seen_at', '>=', $since)->orderByDesc('last_seen_at')->limit(100)->get();

        return $this->decorate($rows)->map(fn (array $r): array => $r + ['ago' => $r['last_seen_at']])->all();
    }

    /** @return Builder<AnalyticsVisit> */
    private function visits(string $area, bool $staff): Builder
    {
        $q = AnalyticsVisit::query()->where('landing_area', $area);

        return $staff ? $q : $q->where('is_staff', false);
    }

    /** @return Builder<AnalyticsHit> */
    private function hits(string $area, bool $staff): Builder
    {
        $q = AnalyticsHit::query()->where('area', $area);
        if (! $staff) {
            $q->whereNotIn('visit_id', AnalyticsVisit::query()->where('is_staff', true)->select('id'));
        }

        return $q;
    }

    /**
     * @param  Builder<AnalyticsVisit>  $visits
     * @param  Builder<AnalyticsHit>  $hits
     * @return array{visits: int, hits: int, bounced: int, logged: int}
     */
    private function totals(Builder $visits, Builder $hits): array
    {
        $v = (clone $visits)->selectRaw('count(*) as n, sum(case when hits <= 1 then 1 else 0 end) as bounced, sum(case when user_id is not null then 1 else 0 end) as logged')->first();

        return ['visits' => (int) ($v->n ?? 0), 'bounced' => (int) ($v->bounced ?? 0), 'logged' => (int) ($v->logged ?? 0), 'hits' => (int) $hits->count()];
    }

    private function delta(int $now, int $before): ?float
    {
        return $before > 0 ? round(($now - $before) * 100 / $before, 1) : null;
    }

    /** Un giorno per volta (ora italiana). @return list<array{label: string, date: string, visits: int, hits: int}> */
    private function daily(string $area, bool $staff, CarbonImmutable $start, CarbonImmutable $end, int $days): array
    {
        $offset = (int) (CarbonImmutable::now('Europe/Rome')->getOffset() / 3600);
        $day = fn (string $col): string => DB::connection()->getDriverName() === 'sqlite' ? "date({$col}, '+{$offset} hours')" : "date({$col} + interval {$offset} hour)";

        $v = $this->visits($area, $staff)->whereBetween('started_at', [$start, $end])->selectRaw($day('started_at').' as d, count(*) as n')->groupBy('d')->pluck('n', 'd');
        $h = $this->hits($area, $staff)->whereBetween('created_at', [$start, $end])->selectRaw($day('created_at').' as d, count(*) as n')->groupBy('d')->pluck('n', 'd');

        $out = [];
        $first = $start->setTimezone('Europe/Rome')->startOfDay();
        for ($i = 0; $i < $days; $i++) {
            $d = $first->addDays($i);
            $key = $d->format('Y-m-d');
            $out[] = ['label' => $d->locale('it')->translatedFormat('j M'), 'date' => $key, 'visits' => (int) ($v[$key] ?? 0), 'hits' => (int) ($h[$key] ?? 0)];
        }

        return $out;
    }

    /**
     * @param  Builder<AnalyticsVisit>  $base
     * @return list<array{label: string, count: int, share: float}>
     */
    private function groupVisits(Builder $base, string $column, int $limit = self::TOP): array
    {
        $total = max(1, (clone $base)->count());
        $rows = (clone $base)->selectRaw("{$column} as k, count(*) as n")->groupBy($column)->orderByDesc('n')->orderBy($column)->limit($limit)->get();

        return $rows->map(fn ($r): array => ['label' => (string) $r->k, 'count' => (int) $r->n, 'share' => round($r->n * 100 / $total, 1)])->all();
    }

    /** @param Builder<AnalyticsVisit> $base @return list<array{label: string, code: string, count: int, share: float}> */
    private function countries(Builder $base): array
    {
        $total = max(1, (clone $base)->count());
        $rows = (clone $base)->whereNotNull('country')->selectRaw('country as k, count(*) as n')->groupBy('country')->orderByDesc('n')->limit(self::TOP)->get();

        return $rows->map(fn ($r): array => ['code' => (string) $r->k, 'label' => self::countryName((string) $r->k), 'count' => (int) $r->n, 'share' => round($r->n * 100 / $total, 1)])->all();
    }

    /** @param Builder<AnalyticsVisit> $base @return list<array{label: string, count: int, share: float}> */
    private function cities(Builder $base): array
    {
        $total = max(1, (clone $base)->count());
        $rows = (clone $base)->whereNotNull('city')->selectRaw('city as k, country as c, count(*) as n')->groupBy('city', 'country')->orderByDesc('n')->limit(self::TOP)->get();

        return $rows->map(fn ($r): array => ['label' => $r->k.($r->c ? ' ('.$r->c.')' : ''), 'count' => (int) $r->n, 'share' => round($r->n * 100 / $total, 1)])->all();
    }

    public static function countryName(string $code): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-'.$code, 'it') : '';

        return $name !== '' && $name !== $code ? $name : $code;
    }

    /**
     * @param  Builder<AnalyticsHit>  $hits
     * @return list<array{label: string, count: int, visits: int, share: float}>
     */
    private function topPages(Builder $hits): array
    {
        $total = max(1, (clone $hits)->count());
        $rows = (clone $hits)->selectRaw('path as k, count(*) as n, count(distinct visit_id) as v')->groupBy('path')->orderByDesc('n')->orderBy('path')->limit(15)->get();

        return $rows->map(fn ($r): array => ['label' => (string) $r->k, 'count' => (int) $r->n, 'visits' => (int) $r->v, 'share' => round($r->n * 100 / $total, 1)])->all();
    }

    /**
     * @param  Builder<AnalyticsHit>  $hits
     * @return array{top: list<array{label: string, count: int, empty: int}>, empty: list<array{label: string, count: int}>, total: int}
     */
    private function searches(Builder $hits): array
    {
        $q = (clone $hits)->whereNotNull('search_term');
        $top = (clone $q)->selectRaw('search_term as k, count(*) as n, sum(case when search_results = 0 then 1 else 0 end) as e')->groupBy('search_term')->orderByDesc('n')->orderBy('search_term')->limit(15)->get();
        $empty = (clone $q)->where('search_results', 0)->selectRaw('search_term as k, count(*) as n')->groupBy('search_term')->orderByDesc('n')->limit(10)->get();

        return [
            'top' => $top->map(fn ($r): array => ['label' => (string) $r->k, 'count' => (int) $r->n, 'empty' => (int) $r->e])->all(),
            'empty' => $empty->map(fn ($r): array => ['label' => (string) $r->k, 'count' => (int) $r->n])->all(),
            'total' => (int) (clone $q)->count(),
        ];
    }

    /**
     * Back-office: chi ha lavorato e dove. @param Builder<AnalyticsHit> $hits
     * @return list<array<string, mixed>>
     */
    private function backPeople(Builder $hits): array
    {
        $rows = (clone $hits)->whereNotNull('user_id')->selectRaw('user_id, count(*) as n, max(created_at) as last_at')->groupBy('user_id')->orderByDesc('n')->limit(25)->get();
        $users = User::query()->whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id');

        return $rows->map(function ($r) use ($users, $hits): array {
            $u = $users->get($r->user_id);
            $top = (clone $hits)->where('user_id', $r->user_id)->selectRaw('path, count(*) as n')->groupBy('path')->orderByDesc('n')->limit(3)->pluck('path')->all();

            return ['name' => $u?->name ?? 'Utente rimosso', 'email' => $u?->email, 'hits' => (int) $r->n, 'last_at' => CarbonImmutable::parse($r->last_at), 'top_pages' => $top];
        })->all();
    }

    /** @param Builder<AnalyticsVisit> $base @return list<array<string, mixed>> */
    private function recent(Builder $base): array
    {
        return $this->decorate((clone $base)->orderByDesc('started_at')->limit(30)->get())->all();
    }

    /**
     * Aggiunge «chi è»: nome, tipo (staff, incaricato, cliente, visitatore).
     *
     * @param  Collection<int, AnalyticsVisit>  $visits
     * @return Collection<int, array<string, mixed>>
     */
    private function decorate(Collection $visits): Collection
    {
        $ids = $visits->pluck('user_id')->filter()->unique();
        $users = User::query()->whereIn('id', $ids)->get(['id', 'name', 'email'])->keyBy('id');
        $codes = Consultant::query()->whereIn('user_id', $ids)->pluck('code', 'user_id');

        return $visits->map(function (AnalyticsVisit $v) use ($users, $codes): array {
            $u = $v->user_id ? $users->get($v->user_id) : null;
            $kind = match (true) {
                $v->user_id === null => 'Visitatore',
                $v->is_staff => 'Staff',
                $codes->has($v->user_id) => 'Incaricato',
                default => 'Cliente',
            };

            return [
                'who' => $u?->name ?? ($v->user_id ? 'Utente rimosso' : 'Visitatore anonimo'), 'email' => $u?->email, 'kind' => $kind, 'code' => $codes->get($v->user_id),
                'area' => $v->current_area, 'path' => $v->current_path, 'landing' => $v->landing_path, 'hits' => (int) $v->hits,
                'source' => $v->source, 'medium' => $v->medium, 'referrer_host' => $v->referrer_host,
                'device' => $v->device, 'browser' => $v->browser, 'os' => $v->os,
                'place' => $this->place($v), 'started_at' => $v->started_at, 'last_seen_at' => $v->last_seen_at,
            ];
        });
    }

    private function place(AnalyticsVisit $v): ?string
    {
        $parts = array_filter([$v->city, $v->country ? self::countryName($v->country) : null]);

        return $parts ? implode(', ', $parts) : null;
    }
}
