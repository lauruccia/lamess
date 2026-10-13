<?php

declare(strict_types=1);

namespace App\Domain\Periods;

use App\Domain\Engine\Calc\KosmosCalculator;
use App\Domain\Engine\Model\AchievedRank;
use App\Domain\Engine\Model\HeldRankAward;
use App\Domain\Engine\Model\InstallmentDue;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\Node;
use App\Domain\Engine\Model\OriginalLine;
use App\Domain\Engine\Model\PeriodInput;
use App\Domain\Engine\Model\PriorState;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use App\Models\Consultant;
use App\Models\ConsultantPeriodStat;
use App\Models\Period;
use App\Models\PvMovement;
use App\Models\RankAchievement;
use App\Models\RankAward;
use App\Models\RankBonusInstallment;
use Brick\Math\BigDecimal;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/** Costruisce lo snapshot (PeriodInput) dal database. Unico ponte DB → motore. */
final class SnapshotBuilder
{
    /**
     * @param  PlanConfig|null  $planOverride  piano diverso da quello del periodo (simulazioni e confronti)
     * @param  bool  $freshCareer  ignora le qualifiche già registrate e ricalcola la carriera da zero
     */
    public static function build(Period $period, ?PlanConfig $planOverride = null, bool $freshCareer = false): PeriodInput
    {
        $key = PeriodKey::parse($period->code);
        $plan = $planOverride ?? $period->planVersion->plan();

        $nodes = Consultant::query()
            ->where('status', '!=', 'pending')
            ->whereRaw('coalesce(network_since, since) <= ?', [$period->ends_at->setTimezone('UTC')->format('Y-m-d H:i:s')])
            ->orderBy('id')
            ->get()
            ->map(static fn (Consultant $c) => new Node(
                id: $c->id, sponsorId: $c->sponsor_id, since: $c->membershipSince(), status: $c->status,
                onboardingComplete: $c->onboarding_complete, bonusHold: $c->bonus_hold,
                rankCapCode: $c->rank_cap_code, name: $c->name,
                rankHoldUntil: $c->rank_hold_until?->format('Y-m-d'),
            ))->all();

        $dbMovements = PvMovement::query()
            ->where('period_id', $period->id)->where('status', 'confirmed')
            ->orderBy('id')->get();
        $customers = $plan->kosmos !== null ? self::customerIds($dbMovements->pluck('id')->all()) : [];
        $movements = $dbMovements->map(static fn (PvMovement $m) => self::movement($m, $customers[$m->id] ?? null))->all();
        $firstPurchase = $plan->kosmos !== null ? self::firstPurchases($period, $plan) : [];

        $prior = self::prior($period, $key, $dbMovements->all());
        if ($freshCareer) {
            $prior = $prior->with(['achievedRanks' => [], 'personalPrOpening' => [], 'rankAwards' => [], 'heldRankAwards' => [], 'installmentsDue' => []]);
        }

        $coverage = $plan->kosmos !== null ? self::customerCoverage($period, $plan) : [];

        return new PeriodInput($key, $plan, $nodes, $movements, $prior, $firstPurchase, $coverage);
    }

    private static function movement(PvMovement $m, ?string $customerId = null): Movement
    {
        return new Movement(
            id: $m->id, ownerId: $m->consultant_id, kind: $m->kind, pv: Num::of($m->pv), cv: Num::of($m->cv),
            at: $m->event_at, reversesId: $m->reverses_movement_id, excluded: $m->excluded_from_bonus, customerId: $customerId,
            amountEur: $m->amount === null ? null : Num::of($m->amount), orderId: $m->order_id === null ? null : (string) $m->order_id,
            vapEur: $m->vap_amount === null ? null : Num::of($m->vap_amount), validityMonths: $m->validity_months === null ? null : (int) $m->validity_months,
            clientCount: $m->client_count === null ? null : (int) $m->client_count,
        );
    }

    /**
     * Cliente acquirente di ciascun movimento pvf legato a un ordine (destinatario della riga, altrimenti chi ha ordinato).
     *
     * @param  list<int>  $movementIds
     * @return array<int, string>
     */
    private static function customerIds(array $movementIds): array
    {
        $out = [];
        foreach (array_chunk($movementIds, 500) as $chunk) {
            $rows = DB::table('pv_movements as pm')
                ->join('orders as o', 'o.id', '=', 'pm.order_id')
                ->leftJoin('order_items as oi', 'oi.id', '=', 'pm.order_item_id')
                ->whereIn('pm.id', $chunk)->where('pm.kind', 'pvf')
                ->selectRaw('pm.id as id, coalesce(oi.recipient_user_id, o.buyer_user_id) as customer')
                ->get();
            foreach ($rows as $r) {
                $out[(int) $r->id] = (string) $r->customer;
            }
        }

        return $out;
    }

    /**
     * Mesi in cui ogni cliente resta valido per l'incaricato (piano Incaricati), e quanti clienti vale: ogni ordine che rende cliente vale i mesi
     * e i clienti del suo prodotto (tra più righe, il valore più alto) a partire dal mese dell'ordine; un nuovo ordine ancora in corso
     * si somma alla scadenza. Le righe interamente rese non contano.
     *
     * @return array<string, array<string, list<array{0:int,1:int,2:int}>>>
     */
    private static function customerCoverage(Period $period, PlanConfig $plan): array
    {
        $cfg = $plan->kosmos;
        if ($cfg === null) {
            return [];
        }
        $rows = PvMovement::query()
            ->join('orders as o', 'o.id', '=', 'pv_movements.order_id')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'pv_movements.order_item_id')
            ->where('pv_movements.status', 'confirmed')->where('pv_movements.pv', '>', 0)
            ->where('pv_movements.excluded_from_bonus', false)->where('pv_movements.event_at', '<=', $period->ends_at)
            ->where(fn ($q) => $q->whereNull('oi.id')->orWhereColumn('oi.qty_refunded', '<', 'oi.qty'))
            ->orderBy('pv_movements.event_at')->orderBy('pv_movements.id')
            ->selectRaw('pv_movements.consultant_id, pv_movements.kind, pv_movements.event_at, pv_movements.order_id, pv_movements.pv, pv_movements.amount, pv_movements.validity_months, pv_movements.client_count, coalesce(oi.recipient_user_id, o.buyer_user_id) as customer')
            ->get();
        /** @var array<string, array<string, array<int, array{0:BigDecimal,1:DateTimeImmutable,2:int,3:int}>>> $orders */
        $orders = [];
        foreach ($rows as $r) {
            $key = $r->kind === 'pvf' ? (string) $r->customer : 'self:'.$r->consultant_id;
            $value = Num::money($r->amount !== null ? Num::of((string) $r->amount) : Num::of((string) $r->pv)->multipliedBy($plan->commissionBasePerPv));
            $months = max(1, (int) ($r->validity_months ?? 1));
            $clients = max(1, (int) ($r->client_count ?? 1));
            [$sum, $at, $maxMonths, $maxClients] = $orders[(string) $r->consultant_id][$key][(int) $r->order_id] ?? [Num::zero(), $r->event_at->toDateTimeImmutable(), 1, 1];
            $orders[(string) $r->consultant_id][$key][(int) $r->order_id] = [$sum->plus($value), $at, max($maxMonths, $months), max($maxClients, $clients)];
        }
        $out = [];
        foreach ($orders as $owner => $customers) {
            foreach ($customers as $customer => $byOrder) {
                $valid = [];
                foreach ($byOrder as [$sum, $at, $months, $clients]) {
                    if ($cfg->orderQualifies($sum)) {
                        $valid[] = ['at' => $at, 'months' => $months, 'clients' => $clients];
                    }
                }
                if ($valid !== []) {
                    $out[$owner][$customer] = KosmosCalculator::coverageIntervals($valid);
                }
            }
        }

        return $out;
    }

    /**
     * Data del primo acquisto valido di ogni cliente per incaricato, fino alla fine del periodo (piano Incaricati).
     * Conta solo l'ordine che rende cliente (qualunque ordine oppure l'importo minimo del piano; le righe di un ordine si sommano).
     *
     * @return array<string, array<string, DateTimeImmutable>>
     */
    private static function firstPurchases(Period $period, PlanConfig $plan): array
    {
        $rows = PvMovement::query()
            ->join('orders as o', 'o.id', '=', 'pv_movements.order_id')
            ->leftJoin('order_items as oi', 'oi.id', '=', 'pv_movements.order_item_id')
            ->where('pv_movements.status', 'confirmed')->where('pv_movements.kind', 'pvf')->where('pv_movements.pv', '>', 0)
            ->where('pv_movements.excluded_from_bonus', false)->where('pv_movements.event_at', '<=', $period->ends_at)
            ->orderBy('pv_movements.event_at')->orderBy('pv_movements.id')
            ->selectRaw('pv_movements.consultant_id, pv_movements.event_at, pv_movements.order_id, pv_movements.pv, pv_movements.amount, coalesce(oi.recipient_user_id, o.buyer_user_id) as customer')
            ->get();
        $cfg = $plan->kosmos;
        /** @var array<string, array<string, array<int, array{0:BigDecimal,1:DateTimeImmutable}>>> $orders */
        $orders = [];
        foreach ($rows as $r) {
            $value = Num::money($r->amount !== null ? Num::of((string) $r->amount) : Num::of((string) $r->pv)->multipliedBy($plan->commissionBasePerPv));
            [$sum, $at] = $orders[(string) $r->consultant_id][(string) $r->customer][(int) $r->order_id] ?? [Num::zero(), $r->event_at->toDateTimeImmutable()];
            $orders[(string) $r->consultant_id][(string) $r->customer][(int) $r->order_id] = [$sum->plus($value), $at];
        }
        $out = [];
        foreach ($orders as $owner => $customers) {
            foreach ($customers as $customer => $byOrder) {
                foreach ($byOrder as [$sum, $at]) {
                    if ($cfg !== null && $cfg->orderQualifies($sum) && (! isset($out[$owner][$customer]) || $at < $out[$owner][$customer])) {
                        $out[$owner][$customer] = $at;
                    }
                }
            }
        }

        return $out;
    }

    /** @param list<PvMovement> $movements */
    private static function prior(Period $period, PeriodKey $key, array $movements): PriorState
    {
        // ultimo periodo chiuso precedente: PR di apertura, attività del mese precedente, prima soglia
        $last = Period::query()->where('status', Period::CLOSED)->where('code', '<', $period->code)
            ->orderByDesc('code')->first();
        $opening = $prevPts = $first = [];
        if ($last !== null && $last->official_run_id !== null) {
            foreach (ConsultantPeriodStat::where('run_id', $last->official_run_id)->cursor() as $s) {
                $id = (string) $s->consultant_id;
                $opening[$id] = Num::of($s->pr_closing);
                if ($last->code === $key->prev()->code()) {
                    $prevPts[$id] = Num::of($s->activity_points);
                }
                if ($s->first_activity_period !== null) {
                    $first[$id] = $s->first_activity_period;
                }
            }
        }

        // Le qualifiche «calcolate» scritte dal mese stesso (ricalcolo notturno) non fanno parte dello stato di partenza:
        // si ricalcolano ogni volta da zero, così un reso toglie anche la qualifica che non è più valida.
        $achieved = RankAchievement::query()
            ->where(fn ($q) => $q->whereNull('period_id')->orWhere('period_id', '!=', $period->id)->orWhere('source', '!=', 'calculated'))
            ->orderBy('id')->get()->map(static fn (RankAchievement $a) => new AchievedRank(
                $a->consultant_id, $a->rank_code, $a->achieved_at, $a->source,
            ))->all();

        $awards = RankAward::query()->pluck('award_key')->all();

        $held = RankAward::query()->where('status', 'held')->orderBy('id')->get()->map(static fn (RankAward $a) => new HeldRankAward(
            $a->award_key, $a->consultant_id, $a->rank_code, Num::of($a->amount), $a->achieved_period,
        ))->all();

        $due = RankBonusInstallment::query()->with('award')
            ->where('status', 'due')->where('due_period', $period->code)->orderBy('id')->get()
            ->map(static fn (RankBonusInstallment $i) => new InstallmentDue(
                $i->award->award_key, $i->award->consultant_id, $i->award->rank_code, $i->number, $i->of, Num::of($i->amount),
            ))->all();

        [$origMovements, $origLines] = self::originals($period, $movements);

        [$fsbMovements, $fsbAwards] = self::fastStart($period);

        return new PriorState(
            personalPrOpening: $opening, achievedRanks: $achieved, rankAwards: $awards, activityPointsPrev: $prevPts,
            firstActivityPeriod: $first, originalLines: $origLines, originalMovements: $origMovements,
            installmentsDue: $due, heldRankAwards: $held, fsbMovements: $fsbMovements, fsbAwards: $fsbAwards,
        );
    }

    /**
     * Fast Start Bonus: movimenti confermati precedenti al periodo (finestra di 210 giorni più margine)
     * e step già riconosciuti nei periodi chiusi.
     *
     * @return array{0:list<Movement>,1:list<string>}
     */
    private static function fastStart(Period $period): array
    {
        if (! $period->planVersion->plan()->fsbEnabled) {
            return [[], []];
        }
        $movements = PvMovement::query()
            ->where('status', 'confirmed')->where('excluded_from_bonus', false)
            ->where('event_at', '>=', $period->starts_at->subDays(220))->where('event_at', '<', $period->starts_at)
            ->orderBy('id')->get()->map(static fn (PvMovement $m) => self::movement($m))->all();

        $runIds = Period::query()->where('status', Period::CLOSED)->where('code', '<', $period->code)
            ->whereNotNull('official_run_id')->pluck('official_run_id')->all();
        $awards = [];
        if ($runIds !== []) {
            $keys = DB::table('commission_lines')->whereIn('run_id', $runIds)->where('bonus_type', 'fsb')
                ->where('engine_status', '!=', 'excluded')->pluck('line_key');
            foreach ($keys as $key) {
                $parts = explode(':', (string) $key); // fsb:{incaricato}:{step}
                if (count($parts) === 3) {
                    $awards[] = $parts[1].':'.$parts[2];
                }
            }
        }

        return [$movements, array_values(array_unique($awards))];
    }

    /**
     * Per i resi che riguardano movimenti di periodi chiusi: movimento originario e righe bloccate.
     *
     * @param  list<PvMovement>  $movements
     * @return array{0:array<string,Movement>,1:array<string,list<OriginalLine>>}
     */
    private static function originals(Period $period, array $movements): array
    {
        $ids = array_values(array_filter(array_map(static fn (PvMovement $m) => $m->reverses_movement_id, $movements)));
        if ($ids === []) {
            return [[], []];
        }
        $originals = PvMovement::query()->whereIn('id', $ids)->where('period_id', '!=', $period->id)
            ->with('period')->get()->filter(static fn (PvMovement $o) => $o->period->isClosed());
        $mov = $lines = [];
        foreach ($originals as $o) {
            $mov[(string) $o->id] = self::movement($o);
            $rows = DB::table('commission_lines')
                ->where('run_id', $o->period->official_run_id)->where('source_movement_id', $o->id)
                ->whereIn('bonus_type', ['direct', 'team'])->where('engine_status', '!=', 'excluded')
                ->orderBy('id')->get();
            $lines[(string) $o->id] = $rows->map(static fn ($r) => new OriginalLine(
                $r->line_key, $r->bonus_type, $r->beneficiary_id, self::dec($r->amount),
                $r->pct === null ? null : self::dec($r->pct), $o->period->code,
            ))->all();
        }

        return [$mov, $lines];
    }

    /** SQLite restituisce float per i DECIMAL: normalizza a stringa per non perdere precisione. */
    private static function dec(mixed $v): BigDecimal
    {
        return Num::of(is_float($v) ? number_format($v, 4, '.', '') : (string) $v);
    }
}
