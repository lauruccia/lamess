<?php

declare(strict_types=1);

namespace App\Domain\Plan;

use App\Domain\Career\RankPalette;
use App\Domain\Engine\Calc\ImpactReport;
use App\Domain\Engine\Calc\KosmosCalculator;
use App\Domain\Engine\Calc\NetworkException;
use App\Domain\Engine\Calc\PeriodCalculator;
use App\Domain\Engine\Calc\Tree;
use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\Node;
use App\Domain\Engine\Model\PeriodInput;
use App\Domain\Engine\Model\PeriodResult;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Support\Num;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\Consultant;
use App\Models\Period;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Simulazione «cosa succede se» del piano Incaricati — SOLA LETTURA.
 *
 * Legge lo snapshot reale del periodo (albero, ordini, clienti), ne fa una copia in memoria, applica le modifiche
 * dell'admin (clienti, ordini, agenti di una certa qualifica, spostamenti di ramo) e rilancia lo stesso motore
 * ufficiale ({@see PeriodCalculator}) prima e dopo. Non scrive nulla: né movimenti, né qualifiche, né compensi, né audit.
 *
 * Le qualifiche sono sempre ricalcolate da zero (come in «Confronto qualifiche»): prima e dopo usano la stessa regola,
 * quindi la differenza dipende solo dalle modifiche simulate.
 */
final class IncaricatiSimulator
{
    public const TYPES = ['clients', 'orders', 'agents', 'move'];

    private const MAX_NEW_MOVEMENTS = 20000;

    private const MAX_NEW_NODES = 3000;

    /**
     * @param  list<array{type:string,target:string|int,count?:int|string,amount?:string,rank?:string,sponsor?:string|int}>  $changes  target e sponsor sono id di incaricati reali
     * @return array<string, mixed>
     */
    public function run(Period $period, PlanConfig $plan, array $changes, bool $cumulativeClients = true, bool $newAgentsInMonth = true): array
    {
        if ($plan->kosmos === null) {
            throw new InvalidArgumentException('La simulazione richiede il piano Incaricati.');
        }
        if ($changes === []) {
            throw new InvalidArgumentException('Aggiungi almeno una modifica da simulare.');
        }
        if ($cumulativeClients) {
            $plan = $plan->withPolicies(['kosmos_client_count' => 'cumulative_buyers']);
        }
        $base = SnapshotBuilder::build($period, $plan, freshCareer: true);
        $draft = new SimulationDraft($base, $newAgentsInMonth);
        foreach (array_values($changes) as $i => $change) {
            $this->apply($draft, $change, $i + 1);
        }
        $scenario = $draft->toInput();

        try {
            $before = PeriodCalculator::calculate($base);
            $after = PeriodCalculator::calculate($scenario);
        } catch (NetworkException $e) {
            throw new InvalidArgumentException('La rete simulata non è valida: '.$e->getMessage());
        }

        return $this->report($period, $plan, $base, $scenario, $before, $after, $draft);
    }

    // ------------------------------------------------------------------ modifiche

    /** @param array<string, mixed> $c */
    private function apply(SimulationDraft $d, array $c, int $n): void
    {
        $type = (string) ($c['type'] ?? '');
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Modifica {$n}: tipo non valido.");
        }
        $target = $this->realNode($d, $c['target'] ?? null, $n);
        $d->targets[$target] = true;
        $who = $this->nodeLabel($d, $target);

        if ($type === 'move') {
            $sponsor = $this->realNode($d, $c['sponsor'] ?? null, $n, 'il nuovo sponsor');
            if ($sponsor === $target) {
                throw new InvalidArgumentException("Modifica {$n}: un incaricato non può essere sponsor di sé stesso.");
            }
            for ($up = $sponsor, $guard = 0; $up !== null && $up !== '' && $guard < 10000; $up = $d->nodes[$up]->sponsorId ?? null, $guard++) {
                if ($up === $target) {
                    throw new InvalidArgumentException("Modifica {$n}: la rete simulata non è valida, lo spostamento crea un ciclo (il nuovo sponsor è sotto l'incaricato spostato).");
                }
            }
            $node = $d->nodes[$target];
            $d->moved[$target] ??= (string) $node->sponsorId;
            $d->nodes[$target] = $node->with(['sponsorId' => $sponsor]);
            $d->targets[$sponsor] = true;

            return;
        }

        if ($type === 'agents') {
            $count = $this->count($c['count'] ?? 1, 1, 20, $n);
            $rank = (string) ($c['rank'] ?? '');
            if (! $d->base->plan->hasCode($rank)) {
                throw new InvalidArgumentException("Modifica {$n}: qualifica «{$rank}» sconosciuta.");
            }
            for ($i = 0; $i < $count; $i++) {
                $this->buildAgent($d, $rank, $target);
            }

            return;
        }

        $count = $this->count($c['count'] ?? 1, 1, 500, $n);
        $amount = $this->amount($d, $c['amount'] ?? null, $n);
        if (! $d->base->plan->kosmos->orderQualifies($amount) && $type === 'clients') {
            $d->warnings[] = "Modifica {$n}: l'ordine da € {$amount} è sotto l'importo minimo che rende cliente ({$d->base->plan->kosmos->clientMinAmount} €): {$who} non avrà nuovi clienti.";
        }
        if ($type === 'clients') {
            $this->addClients($d, $target, $count, $amount);

            return;
        }
        // ordini aggiuntivi: da clienti che l'incaricato ha già (a rotazione); se non ne ha, da un unico cliente simulato
        $existing = $this->existingCustomers($d, $target);
        if ($existing === []) {
            $d->warnings[] = "Modifica {$n}: {$who} non ha ancora clienti, gli ordini aggiuntivi arrivano da un unico cliente simulato.";
            $existing = ['sim-c-ordini-'.$target];
        }
        for ($i = 0; $i < $count; $i++) {
            $this->addMovement($d, $target, $existing[$i % count($existing)], $amount);
            $d->ordersAdded++;
        }
    }

    private function realNode(SimulationDraft $d, mixed $id, int $n, string $what = "l'incaricato"): string
    {
        $id = (string) $id;
        if ($id === '' || ! isset($d->nodes[$id]) || $d->isVirtual($id)) {
            throw new InvalidArgumentException("Modifica {$n}: scegli {$what} dall'elenco (deve essere in rete nel periodo scelto).");
        }

        return $id;
    }

    private function count(mixed $v, int $min, int $max, int $n): int
    {
        if (! is_numeric($v) || (int) $v != $v || (int) $v < $min || (int) $v > $max) {
            throw new InvalidArgumentException("Modifica {$n}: la quantità deve essere un numero intero tra {$min} e {$max}.");
        }

        return (int) $v;
    }

    private function amount(SimulationDraft $d, mixed $v, int $n): BigDecimal
    {
        if ($v === null || trim((string) $v) === '') {
            $min = $d->base->plan->kosmos->clientMinAmount;

            return $min->isPositive() ? Num::money($min) : Num::money('150');
        }
        $s = str_replace(',', '.', trim((string) $v));
        if (! is_numeric($s) || (float) $s <= 0 || (float) $s > 100000) {
            throw new InvalidArgumentException("Modifica {$n}: l'importo dell'ordine non è valido.");
        }

        return Num::money($s);
    }

    /** @return list<string> */
    private function existingCustomers(SimulationDraft $d, string $owner): array
    {
        $out = [];
        foreach ($d->base->customerFirstPurchase[$owner] ?? [] as $customer => $_) {
            $out[(string) $customer] = true;
        }
        foreach ($d->movements as $m) {
            if ($m->ownerId === $owner && $m->kind === 'pvf' && $m->customerId !== null) {
                $out[$m->customerId] = true;
            }
        }
        $ids = array_map('strval', array_keys($out));
        sort($ids, SORT_STRING);

        return $ids;
    }

    private function addClients(SimulationDraft $d, string $owner, int $count, BigDecimal $amount): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->addMovement($d, $owner, $d->nextId('c'), $amount);
        }
        $d->clientsAdded += $count;
    }

    private function addMovement(SimulationDraft $d, string $owner, string $customer, BigDecimal $amount): void
    {
        if (count($d->movements) - count($d->base->movements) >= self::MAX_NEW_MOVEMENTS) {
            throw new InvalidArgumentException('La simulazione è troppo grande: riduci clienti, ordini o agenti.');
        }
        $perPv = $d->base->plan->commissionBasePerPv;
        $pv = $perPv->isPositive() ? Num::points($amount->dividedBy($perPv, 6, RoundingMode::HalfUp)) : Num::points($amount);
        $d->movements[] = new Movement(
            id: $d->nextId('m'), ownerId: $owner, kind: 'pvf', pv: $pv, cv: $pv, at: $d->at,
            customerId: $customer, amountEur: Num::money($amount), orderId: $d->nextId('o'),
        );
    }

    /**
     * Crea un agente virtuale della qualifica richiesta sotto lo sponsor, con la struttura minima per raggiungerla:
     * clienti, Pearl al 1° livello, linee con la qualifica richiesta e colonne da N clienti. Si uniscono i requisiti
     * di tutte le qualifiche fino a quella richiesta, perché il motore le fa superare in sequenza.
     *
     * @return array{0:string,1:int} [id dell'agente, clienti in tutto il suo ramo]
     */
    private function buildAgent(SimulationDraft $d, string $rankCode, string $sponsorId, ?string $topId = null): array
    {
        $plan = $d->base->plan;
        if (count($d->virtual) >= self::MAX_NEW_NODES) {
            throw new InvalidArgumentException('La simulazione è troppo grande: riduci il numero o la qualifica degli agenti.');
        }
        $rank = $plan->rank($rankCode);
        $id = $d->nextId('a');
        $top = $topId ?? $id;
        $d->nodes[$id] = new Node($id, $sponsorId, $d->joinedAt, 'active', true, false, null, "Nuovo {$rank->name} (simulato)");
        $d->virtual[$id] = ['rank' => $rankCode, 'top' => $top, 'sponsor' => $sponsorId];

        $needClients = $needPearl = $needColumns = $needColumnClients = 0;
        $slots = [];
        foreach ($plan->ranks as $r) {
            if ($r->sequence === 0 || $r->sequence > $rank->sequence) {
                continue;
            }
            if ($r->requires('clients')) {
                $needClients = max($needClients, $r->minClients);
            }
            if ($r->requires('pearl')) {
                $needPearl = max($needPearl, $r->minPearlFirstLevel);
            }
            if ($r->requires('columns') && $r->minColumns > 0 && $r->minColumnClients > 0) {
                $needColumns = max($needColumns, $r->minColumns);
                $needColumnClients = max($needColumnClients, $r->minColumnClients);
            }
            if ($r->requires('structure')) {
                foreach ($r->structure as $req) {
                    $slots[$req->rankCode] = max($slots[$req->rankCode] ?? 0, $req->count);
                }
            }
        }

        $amount = $this->amount($d, null, 0);
        $total = 0;
        if ($needClients > 0) {
            $this->addClients($d, $id, $needClients, $amount);
            $total += $needClients;
        }

        $pearlCode = $plan->kosmos->pearlRank;
        $pearlSeq = $plan->rank($pearlCode)->sequence;
        $childRanks = [];
        foreach ($slots as $code => $cnt) {
            for ($i = 0; $i < $cnt; $i++) {
                $childRanks[] = $code;
            }
        }
        $pearlLike = count(array_filter($childRanks, static fn (string $c) => $plan->rank($c)->sequence >= $pearlSeq));
        for (; $pearlLike < $needPearl; $pearlLike++) {
            $childRanks[] = $pearlCode;
        }
        while (count($childRanks) < $needColumns) {
            $childRanks[] = $pearlCode;
        }

        $children = [];
        foreach ($childRanks as $code) {
            $children[] = $this->buildAgent($d, $code, $id, $top);
        }
        foreach ($children as $i => [$childId, $sub]) {
            if ($i < $needColumns && $sub < $needColumnClients) {
                $missing = $needColumnClients - $sub;
                $this->addClients($d, $childId, $missing, $amount);
                $children[$i][1] = $sub + $missing;
            }
        }
        foreach ($children as [, $sub]) {
            $total += $sub;
        }

        return [$id, $total];
    }

    // ------------------------------------------------------------------ confronto

    /** @return array<string, array{achieved:string,effective:string,clients:int,pearl:int,earned:BigDecimal,missing:string,next:?string}> */
    private function snapshot(PeriodInput $in, PeriodResult $res): array
    {
        $plan = $in->plan;
        $closed = [];
        foreach ($in->movements as $m) {
            if ($m->reversesId !== null && isset($in->prior->originalMovements[$m->reversesId])) {
                $closed[$m->id] = true;
            }
        }
        $clients = KosmosCalculator::clientCounts($plan, $in->period, $in->movements, $closed, $in->customerFirstPurchase, $in->customerCoverage);
        $tree = new Tree($in->nodes);
        $pearlSeq = $plan->rank($plan->kosmos->pearlRank)->sequence;
        $out = [];
        foreach ($res->stats as $id => $st) {
            $id = (string) $id;
            $pearl = 0;
            foreach ($tree->childrenOf($id) as $c) {
                if ($tree->isMember($c) && $plan->rank($res->stats[$c]->achievedRank)->sequence >= $pearlSeq) {
                    $pearl++;
                }
            }
            $next = $st->nextRank ?? [];
            $out[$id] = [
                'achieved' => $st->achievedRank, 'effective' => $st->effectiveRank,
                'clients' => (int) ($clients[$id] ?? 0), 'pearl' => $pearl, 'earned' => $res->earned($id),
                'missing' => self::missingText($next), 'next' => ($next['rank'] ?? null) !== null ? (string) $next['rank'] : null,
            ];
        }

        return $out;
    }

    /** @param array<string, mixed> $next */
    private static function missingText(array $next): string
    {
        if ($next === []) {
            return '';
        }
        $missing = [];
        if (isset($next['clients_required']) && ! ($next['clients_ok'] ?? true)) {
            $missing[] = "clienti {$next['clients_counted']}/{$next['clients_required']}";
        }
        if (isset($next['pearl_first_level_required']) && ! ($next['pearl_first_level_ok'] ?? true)) {
            $missing[] = "Pearl 1° liv. {$next['pearl_first_level_counted']}/{$next['pearl_first_level_required']}";
        }
        if (! empty($next['structure_missing'])) {
            $missing[] = 'linee: '.implode(', ', $next['structure_missing']);
        }
        if (isset($next['columns_required']) && ! ($next['columns_ok'] ?? true)) {
            $missing[] = "colonne da {$next['column_clients_required']} clienti {$next['columns_counted']}/{$next['columns_required']}";
        }

        return implode(' · ', $missing);
    }

    /** @return array<string, mixed> */
    private function report(Period $period, PlanConfig $plan, PeriodInput $baseIn, PeriodInput $scenIn, PeriodResult $before, PeriodResult $after, SimulationDraft $d): array
    {
        $b = $this->snapshot($baseIn, $before);
        $a = $this->snapshot($scenIn, $after);
        $seq = static fn (string $code): int => $plan->hasCode($code) ? $plan->rank($code)->sequence : -1;
        $name = static fn (string $code): string => $plan->hasCode($code) ? $plan->rank($code)->name : $code;
        $labels = $this->labels($d, array_keys($a));
        $explain = $this->explainEarnings($before, $after, $labels, $plan->kosmos->structureClients);

        $rows = [];
        foreach ($b as $id => $was) {
            $now = $a[$id];
            $dir = $seq($now['achieved']) <=> $seq($was['achieved']);
            $changed = $dir !== 0 || $now['effective'] !== $was['effective'] || $now['clients'] !== $was['clients']
                || $now['pearl'] !== $was['pearl'] || ! $now['earned']->isEqualTo($was['earned']);
            if (! $changed && ! isset($d->targets[$id])) {
                continue;
            }
            $rows[] = [
                'id' => (string) $id, 'label' => $labels[$id] ?? $id, 'target' => isset($d->targets[$id]), 'moved' => isset($d->moved[$id]),
                'dir' => $dir, 'rank_before' => $name($was['achieved']), 'rank_after' => $name($now['achieved']),
                'paid_before' => $name($was['effective']), 'paid_after' => $name($now['effective']),
                'clients_before' => $was['clients'], 'clients_after' => $now['clients'],
                'pearl_before' => $was['pearl'], 'pearl_after' => $now['pearl'],
                'next_after' => $now['next'] === null ? null : $name($now['next']), 'missing_after' => $now['missing'],
                'explain' => $explain[$id] ?? [], 'earned_before' => (string) $was['earned'], 'earned_after' => (string) $now['earned'], 'earned_diff' => (string) $now['earned']->minus($was['earned']),
            ];
        }
        usort($rows, static fn (array $x, array $y) => [$y['dir'], $y['target'] ? 1 : 0, $x['label']] <=> [$x['dir'], $x['target'] ? 1 : 0, $y['label']]);

        $agents = [];
        $virtualClients = [];
        foreach ($d->movements as $m) {
            if (isset($d->virtual[$m->ownerId]) && $m->kind === 'pvf') {
                $virtualClients[$m->ownerId] = ($virtualClients[$m->ownerId] ?? []) + [(string) $m->customerId => true];
            }
        }
        $sub = [];
        foreach ($d->virtual as $id => $v) {
            $sub[$v['top']]['agents'] = ($sub[$v['top']]['agents'] ?? 0) + 1;
            $sub[$v['top']]['clients'] = ($sub[$v['top']]['clients'] ?? 0) + count($virtualClients[$id] ?? []);
        }
        foreach ($d->virtual as $id => $v) {
            if ($v['top'] !== $id) {
                continue;
            }
            $got = $a[$id]['achieved'] ?? '';
            $agents[] = [
                'id' => $id, 'sponsor' => $labels[$v['sponsor']] ?? $v['sponsor'], 'requested' => $name($v['rank']), 'reached' => $name($got),
                'ok' => $seq($got) >= $seq($v['rank']), 'sub_agents' => $sub[$id]['agents'] - 1, 'sub_clients' => $sub[$id]['clients'],
                'earned' => (string) ($a[$id]['earned'] ?? '0'),
            ];
        }

        $impact = ImpactReport::compare($before, $after);
        $totals = [];
        foreach ($impact['totals'] as $bonus => $t) {
            $totals[] = ['bonus' => $bonus, 'before' => (string) $t['base'], 'after' => (string) $t['alt'], 'diff' => (string) $t['diff']];
        }
        $beforeTotal = $before->total();
        $afterTotal = $after->total();

        $warnings = array_values(array_unique($d->warnings));
        foreach ($agents as $ag) {
            if (! $ag['ok']) {
                $warnings[] = "L'agente simulato sotto {$ag['sponsor']} ha raggiunto «{$ag['reached']}» invece di «{$ag['requested']}»: i requisiti del piano sono cambiati o non sono tutti soddisfacibili con clienti e agenti.";
            }
        }

        return [
            'period' => $period->code, 'plan' => $plan->label,
            'summary' => [
                'clients' => $d->clientsAdded, 'orders' => $d->ordersAdded, 'virtual_agents' => count($d->virtual), 'moves' => count($d->moved),
                'rank_up' => count(array_filter($rows, static fn (array $r) => $r['dir'] > 0)),
                'rank_down' => count(array_filter($rows, static fn (array $r) => $r['dir'] < 0)),
                'total_before' => (string) $beforeTotal, 'total_after' => (string) $afterTotal, 'total_diff' => (string) $afterTotal->minus($beforeTotal),
            ],
            'rows' => $rows, 'agents' => $agents, 'totals' => $totals,
            'tree' => $this->treeRows($d, $scenIn, $a, $b, $labels, $name, $seq, $explain),
            'warnings' => $warnings,
        ];
    }

    /**
     * Spiega, per ogni incaricato, da dove nasce la differenza di compensi: le righe nuove o cambiate tra prima e dopo,
     * raggruppate per tipo, livello e vendita di origine, con base, aliquota e requisiti usati dal motore.
     *
     * @param  array<string, string>  $labels
     * @return array<string, list<string>> id incaricato => righe di spiegazione (la prima è il riepilogo)
     */
    private function explainEarnings(PeriodResult $before, PeriodResult $after, array $labels, int $structureClients): array
    {
        $index = static function (PeriodResult $r): array {
            $out = [];
            foreach ($r->lines as $l) {
                if (in_array($l->status, ['accrued', 'held'], true)) {
                    $out[$l->beneficiaryId][$l->key] = $l;
                }
            }

            return $out;
        };
        $b = $index($before);
        $a = $index($after);
        $eur = static fn (BigDecimal|string $v): string => number_format((float) (string) $v, 2, ',', '.');
        $pct = static fn (?BigDecimal $r): string => $r === null ? '—' : rtrim(rtrim(Num::pctLabel($r), '0'), '.').'%';
        $names = ['direct' => 'Compensi diretti', 'indirect' => 'Compensi indiretti', 'indirect_ext' => 'Indiretti estesi', 'structure' => 'Bonus di Struttura'];

        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $id) {
            $id = (string) $id;
            $groups = [];
            $keys = array_unique(array_merge(array_keys($a[$id] ?? []), array_keys($b[$id] ?? [])));
            $total = Num::zero();
            foreach ($keys as $key) {
                $new = $a[$id][$key] ?? null;
                $old = $b[$id][$key] ?? null;
                $diff = ($new?->amount ?? Num::zero())->minus($old?->amount ?? Num::zero());
                if ($diff->isZero()) {
                    continue;
                }
                $ref = $new ?? $old;
                $total = $total->plus($diff);
                $g = $ref->bonus.'|'.($ref->level ?? 0).'|'.($ref->sourcePartnerId ?? '').'|'.($old?->rate === null ? '' : $pct($old->rate)).'|'.($new?->rate === null ? '' : $pct($new->rate));
                $groups[$g]['ref'] = $ref;
                $groups[$g]['new'] = $new;
                $groups[$g]['old'] = $old;
                $groups[$g]['n'] = ($groups[$g]['n'] ?? 0) + 1;
                $groups[$g]['diff'] = ($groups[$g]['diff'] ?? Num::zero())->plus($diff);
            }
            if ($groups === []) {
                continue;
            }
            $lines = [];
            foreach ($groups as $g) {
                /** @var Line $ref */
                $ref = $g['ref'];
                $source = $labels[$ref->sourcePartnerId ?? ''] ?? ($ref->sourcePartnerId ?? '');
                $sales = $g['n'] === 1 ? '1 vendita' : $g['n'].' vendite';
                $ex = $ref->explanation;
                if ($ref->bonus === 'structure') {
                    $lines[] = "Bonus di Struttura {$eur($g['diff'])} €: {$source} porta {$structureClients} clienti entro la finestra dall'attivazione (PearlQ); all'upline spetta la differenza tra i bonus delle qualifiche ({$ex['formula']}).";

                    continue;
                }
                $title = $names[$ref->bonus] ?? $ref->bonus;
                if ($ref->bonus !== 'direct') {
                    $title .= ' livello '.($ref->level ?? '?');
                }
                $text = "{$title}: ".($g['diff']->isPositive() ? '+' : '').$eur($g['diff'])." € — {$sales}";
                $text .= $ref->bonus === 'direct' && $ref->sourcePartnerId === $id ? ' ai propri clienti' : " di {$source}";
                $text .= ", base V.A.P. {$eur($ref->base)} € × ".$pct($g['new']?->rate ?? $ref->rate);
                if ($g['old'] !== null && $g['new'] !== null && $g['old']->rate !== null && ! $g['old']->rate->isEqualTo($g['new']->rate)) {
                    $text .= ' (aliquota prima '.$pct($g['old']->rate).': è salito lo scaglione)';
                } elseif ($g['old'] === null) {
                    $text .= ' (nuovo: prima non maturava)';
                }
                if ($ref->bonus === 'direct' && isset($ex['clients'])) {
                    $text .= "; clienti personali {$ex['clients']}";
                } elseif (isset($ex['clients'], $ex['min_clients'])) {
                    $text .= "; requisiti del livello: clienti {$ex['clients']}/{$ex['min_clients']}, Pearl 1° liv. {$ex['pearl_first_level']}/{$ex['min_pearl']}";
                }
                $lines[] = $text.'.';
            }
            if (count($lines) > 8) {
                $more = count($lines) - 8;
                $lines = array_slice($lines, 0, 8);
                $lines[] = "… e altre {$more} voci.";
            }
            $sum = static fn (array $lines): BigDecimal => array_reduce($lines, static fn (BigDecimal $c, $l) => $c->plus($l->amount), Num::zero());
            $out[$id] = array_merge(["Compensi del mese: prima {$eur($sum($b[$id] ?? []))} € → dopo {$eur($sum($a[$id] ?? []))} €. Da dove nasce la differenza:"], $lines);
        }

        return $out;
    }

    /**
     * Etichette «CODICE — Nome» degli incaricati reali e nome degli agenti simulati.
     *
     * @param  list<int|string>  $ids
     * @return array<string, string>
     */
    private function labels(SimulationDraft $d, array $ids): array
    {
        $real = [];
        foreach ($ids as $id) {
            $id = (string) $id;
            if (! $d->isVirtual($id)) {
                $real[] = (int) $id;
            }
        }
        $out = [];
        foreach (array_chunk($real, 500) as $chunk) {
            foreach (Consultant::query()->whereIn('id', $chunk)->get(['id', 'code', 'name']) as $c) {
                $out[(string) $c->id] = "{$c->code} — {$c->name}";
            }
        }
        foreach ($d->virtual as $id => $_) {
            $out[$id] = $d->nodes[$id]->name;
        }

        return $out;
    }

    private function nodeLabel(SimulationDraft $d, string $id): string
    {
        return $this->labels($d, [$id])[$id] ?? $id;
    }

    /**
     * Albero completo della rete dopo le modifiche, in ordine di visita: ogni incaricato con qualifica prima e dopo, clienti e
     * compensi. Gli agenti simulati annidati sono riassunti nell'agente simulato in cima al loro ramo.
     *
     * @param  array<string, array<string, mixed>>  $a
     * @param  array<string, array<string, mixed>>  $b
     * @param  array<string, string>  $labels
     * @return list<array<string, mixed>>
     */
    private function treeRows(SimulationDraft $d, PeriodInput $scenIn, array $a, array $b, array $labels, callable $name, callable $seq, array $explain = []): array
    {
        $tree = new Tree($scenIn->nodes);
        $plan = $scenIn->plan;
        $sub = [];
        foreach ($d->virtual as $id => $v) {
            if ($v['top'] !== $id) {
                $sub[$v['top']] = ($sub[$v['top']] ?? 0) + 1;
            }
        }
        $color = static fn (?string $code): ?string => $code === null ? null : RankPalette::color($code, $plan->hasCode($code) ? $plan->rank($code)->sequence : 0);

        $rows = [];
        $walk = function (string $id, int $depth, ?string $parent) use (&$walk, &$rows, $tree, $d, $a, $b, $labels, $name, $seq, $sub, $color, $explain): void {
            $virtual = $d->isVirtual($id);
            if ($virtual && $d->virtual[$id]['top'] !== $id) {
                return;
            }
            $now = $a[$id] ?? null;
            $was = $b[$id] ?? null;
            $dir = ($was !== null && $now !== null) ? $seq($now['achieved']) <=> $seq($was['achieved']) : 0;
            $moved = isset($d->moved[$id]);
            $tag = match (true) {
                $virtual => 'new',
                $moved => 'moved',
                $dir > 0 => 'up',
                $dir < 0 => 'down',
                isset($d->targets[$id]) => 'target',
                default => '',
            };
            $earnedDiff = ($now !== null && $was !== null) ? $now['earned']->minus($was['earned']) : ($now['earned'] ?? null);
            $changed = $virtual || $moved || $dir !== 0 || ($was !== null && $now !== null && ($now['clients'] !== $was['clients'] || $now['pearl'] !== $was['pearl'] || ! $earnedDiff->isZero()));
            $rows[] = [
                'id' => $id, 'parent' => $parent, 'depth' => $depth, 'label' => $labels[$id] ?? $id, 'tag' => $tag, 'virtual' => $virtual,
                'target' => isset($d->targets[$id]), 'changed' => $changed, 'dir' => $dir, 'moved_from' => $moved ? ($labels[$d->moved[$id]] ?? null) : null,
                'rank_before' => $was === null ? null : $name($was['achieved']), 'rank_after' => $now === null ? null : $name($now['achieved']),
                'color_before' => $color($was['achieved'] ?? null), 'color_after' => $color($now['achieved'] ?? null),
                'clients_before' => $was['clients'] ?? null, 'clients_after' => $now['clients'] ?? 0,
                'earned_diff' => $earnedDiff === null ? '0' : (string) $earnedDiff, 'extra' => $virtual ? ($sub[$id] ?? 0) : 0,
                'explain' => $explain[$id] ?? [], 'sub_changes' => 0,
            ];
            foreach ($tree->childrenOf($id) as $child) {
                $walk($child, $depth + 1, $id);
            }
        };
        foreach ($tree->children[''] ?? [] as $root) {
            $walk($root, 0, null);
        }

        // quanti cambiamenti ci sono sotto ogni nodo: serve a non perderli quando un ramo è chiuso
        $index = [];
        foreach ($rows as $i => $r) {
            $index[$r['id']] = $i;
        }
        foreach ($rows as $r) {
            if (! $r['changed']) {
                continue;
            }
            for ($p = $r['parent']; $p !== null; $p = $rows[$index[$p]]['parent']) {
                $rows[$index[$p]]['sub_changes']++;
            }
        }

        return $rows;
    }
}
