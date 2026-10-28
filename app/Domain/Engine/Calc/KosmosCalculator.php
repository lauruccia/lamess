<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Plan\KosmosConfig;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Piano «Incaricati» (motore kosmos_v1): presentazioni «Lamèss Incaricati» e «Seconda parte».
 *
 *  - Compensi diretti: % per scaglione di clienti personali, sul VAP (66,66666% dell'importo in euro, IVA inclusa).
 *  - Compensi indiretti: 5 livelli, ciascuno con clienti personali e Pearl al 1° livello minimi.
 *  - Indiretti estesi: Ruby/Emerald/Diamond oltre il 5° livello, fino al 5° livello del successivo pari qualifica.
 *  - Bonus di Struttura (PearlQ): 3 clienti entro 30 giorni dall'attivazione; differenziale per qualifica dell'upline.
 *
 * Tutti i valori vengono da {@see KosmosConfig}. I punti (PV) non esistono più nel piano: contano gli importi in euro.
 * Un cliente è chi fa un ordine valido secondo la regola scelta dall'admin (qualunque ordine, oppure un importo minimo).
 */
final class KosmosCalculator
{
    /** Chiave del cliente di un movimento (null = non conta come cliente). L'acquisto proprio è un cliente «self:{id}» se la politica è attiva. */
    public static function clientKey(PlanConfig $plan, Movement $m): ?string
    {
        if ($m->excluded || ! $m->pv->isPositive()) {
            return null;
        }
        if ($m->kind === 'pvf') {
            return $m->customerId;
        }

        return $plan->policies->is('kosmos_own_purchase_as_client', 'on') ? 'self:'.$m->ownerId : null;
    }

    /** Il movimento genera compensi (vendita a un cliente, o acquisto proprio se la politica è attiva). */
    public static function isCommissionable(PlanConfig $plan, Movement $m): bool
    {
        return $m->kind === 'pvf' || $plan->policies->is('kosmos_own_purchase_as_client', 'on');
    }

    /**
     * Importo in euro (IVA inclusa) del movimento. I movimenti senza importo (importati dal vecchio sistema)
     * si convertono con «euro per punto» del piano (1,5 = una Box da 150 € per 100 punti).
     */
    public static function amountOf(PlanConfig $plan, Movement $m): BigDecimal
    {
        return Num::money($m->amountEur ?? $m->pv->multipliedBy($plan->commissionBasePerPv));
    }

    /**
     * Prima data di acquisto valido di ogni cliente, per incaricato: solo gli ordini che rendono cliente
     * (qualunque ordine, oppure almeno l'importo minimo scelto dall'admin; le righe dello stesso ordine si sommano).
     *
     * @param  list<Movement>  $movements
     * @param  array<string, true>  $closedReversals
     * @return array<string, array<string, DateTimeImmutable>> incaricato => cliente => data
     */
    public static function qualifyingPurchases(PlanConfig $plan, array $movements, array $closedReversals): array
    {
        $cfg = $plan->kosmos;
        if ($cfg === null) {
            return [];
        }
        /** @var array<string, array<string, array<string, array{0:BigDecimal,1:DateTimeImmutable}>>> $orders */
        $orders = [];
        foreach ($movements as $m) {
            $key = self::clientKey($plan, $m);
            if ($key === null || isset($closedReversals[$m->id])) {
                continue;
            }
            $order = $m->orderId ?? 'm:'.$m->id;
            [$sum, $at, $byProduct] = $orders[$m->ownerId][$key][$order] ?? [Num::zero(), $m->at, false];
            $orders[$m->ownerId][$key][$order] = [$sum->plus(self::amountOf($plan, $m)), min($at, $m->at), $byProduct || $m->makesClient === true];
        }
        $out = [];
        foreach ($orders as $owner => $customers) {
            foreach ($customers as $customer => $byOrder) {
                foreach ($byOrder as [$sum, $at, $byProduct]) {
                    if ($cfg->orderQualifies($sum, $byProduct) && (! isset($out[$owner][$customer]) || $at < $out[$owner][$customer])) {
                        $out[$owner][$customer] = $at;
                    }
                }
            }
        }

        return $out;
    }

    /** Clienti personali distinti per incaricato, secondo la politica kosmos_client_count.
     *
     * @param  list<Movement>  $movements
     * @param  array<string, true>  $closedReversals
     * @param  array<string, array<string, DateTimeImmutable>>  $firstPurchase
     * @param  array<string, array<string, list<array{0:int,1:int,2:int}>>>  $coverage  tratti di validità dei clienti [primo mese, ultimo mese, clienti] (solo con kosmos_client_count = period_buyers)
     * @return array<string, int>
     */
    public static function clientCounts(PlanConfig $plan, PeriodKey $period, array $movements, array $closedReversals, array $firstPurchase, array $coverage = []): array
    {
        $out = [];
        if ($plan->policies->is('kosmos_client_count', 'cumulative_buyers')) {
            foreach (self::mergedFirstPurchase($plan, $movements, $closedReversals, $firstPurchase) as $owner => $customers) {
                $out[$owner] = count(array_filter($customers, static fn (DateTimeImmutable $d) => $d <= $period->end()));
            }

            return $out;
        }
        // Clienti del mese (acquisto nel mese) più quelli ancora validi per i mesi del prodotto acquistato prima.
        // Ogni cliente pesa quanti clienti vale il suo prodotto (1 se non indicato).
        $weights = self::qualifyingClientWeights($plan, $movements, $closedReversals);
        $month = self::monthIndex($period->start());
        $ownOn = $plan->policies->is('kosmos_own_purchase_as_client', 'on');
        foreach ($coverage as $owner => $customers) {
            foreach ($customers as $customer => $segments) {
                if (! $ownOn && str_starts_with((string) $customer, 'self:')) {
                    continue;
                }
                foreach ($segments as [$first, $last, $clients]) {
                    if ($first <= $month && $month <= $last) {
                        $weights[(string) $owner][(string) $customer] = max($weights[(string) $owner][(string) $customer] ?? 0, $clients);

                        break;
                    }
                }
            }
        }
        foreach ($weights as $owner => $customers) {
            $out[(string) $owner] = array_sum($customers);
        }

        return $out;
    }

    /**
     * Clienti del mese per incaricato con il loro peso (quanti clienti vale l'ordine: il valore più alto tra le righe).
     *
     * @param  list<Movement>  $movements
     * @param  array<string, true>  $closedReversals
     * @return array<string, array<string, int>> incaricato => cliente => peso
     */
    private static function qualifyingClientWeights(PlanConfig $plan, array $movements, array $closedReversals): array
    {
        $cfg = $plan->kosmos;
        if ($cfg === null) {
            return [];
        }
        $orders = [];
        foreach ($movements as $m) {
            $key = self::clientKey($plan, $m);
            if ($key === null || isset($closedReversals[$m->id])) {
                continue;
            }
            $order = $m->orderId ?? 'm:'.$m->id;
            [$sum, $weight, $byProduct] = $orders[$m->ownerId][$key][$order] ?? [Num::zero(), 1, false];
            $orders[$m->ownerId][$key][$order] = [$sum->plus(self::amountOf($plan, $m)), max($weight, $m->clientCount ?? 1), $byProduct || $m->makesClient === true];
        }
        $out = [];
        foreach ($orders as $owner => $customers) {
            foreach ($customers as $customer => $byOrder) {
                foreach ($byOrder as [$sum, $weight, $byProduct]) {
                    if ($cfg->orderQualifies($sum, $byProduct)) {
                        $out[$owner][$customer] = max($out[$owner][$customer] ?? 0, $weight);
                    }
                }
            }
        }

        return $out;
    }

    /** Indice del mese (anno × 12 + mese − 1). */
    public static function monthIndex(DateTimeImmutable $d): int
    {
        return ((int) $d->format('Y')) * 12 + ((int) $d->format('n')) - 1;
    }

    /**
     * Tratti di validità di un cliente. Il cliente vale nel mese dell'acquisto (contato a parte); gli N mesi del prodotto
     * partono dal mese successivo e finiscono con l'ultimo giorno dell'N-esimo mese. Esempio: ordine del 15/10/2026 da
     * 1 mese → 1/11–30/11/2026; da 2 mesi → fino al 31/12/2026; da 12 mesi → fino al 31/10/2027.
     * Se l'ordine arriva mentre la validità è ancora in corso, i suoi mesi partono dalla scadenza attuale (si sommano);
     * se è già scaduta, partono dal mese successivo al nuovo ordine. Ogni tratto porta quanti clienti vale il prodotto
     * che lo ha generato.
     *
     * @param  list<array{at: DateTimeImmutable, months: int, clients?: int}>  $orders
     * @return list<array{0:int,1:int,2:int}> tratti [primo mese, ultimo mese, clienti]
     */
    public static function coverageIntervals(array $orders): array
    {
        usort($orders, static fn (array $a, array $b) => $a['at'] <=> $b['at']);
        $out = [];
        $end = null;
        foreach ($orders as $o) {
            $idx = self::monthIndex($o['at']);
            $start = ($end !== null && $idx <= $end) ? $end + 1 : $idx + 1;
            $end = $start + max(1, $o['months']) - 1;
            $out[] = [$start, $end, max(1, $o['clients'] ?? 1)];
        }
        return $out;
    }

    /**
     * @param  list<Movement>  $movements
     * @param  array<string, true>  $closedReversals
     * @param  array<string, array<string, DateTimeImmutable>>  $firstPurchase
     * @return array<string, array<string, DateTimeImmutable>>
     */
    private static function mergedFirstPurchase(PlanConfig $plan, array $movements, array $closedReversals, array $firstPurchase): array
    {
        $all = $firstPurchase;
        foreach (self::qualifyingPurchases($plan, $movements, $closedReversals) as $owner => $customers) {
            foreach ($customers as $customer => $at) {
                $cur = $all[$owner][$customer] ?? null;
                if ($cur === null || $at < $cur) {
                    $all[$owner][$customer] = $at;
                }
            }
        }

        return $all;
    }

    /**
     * @param  list<Movement>  $movements
     * @param  array<string, int>  $clients
     * @param  array<string, true>  $closedReversals
     * @param  array<string, array<string, DateTimeImmutable>>  $firstPurchase
     * @return list<Line>
     */
    public static function calculate(BonusContext $ctx, array $movements, array $clients, array $closedReversals, array $firstPurchase): array
    {
        $cfg = $ctx->plan->kosmos;
        if ($cfg === null) {
            return [];
        }
        $lines = [];
        $compress = $ctx->plan->policies->is('kosmos_compression', 'skip');
        $pearlSeq = $ctx->plan->rank($cfg->pearlRank)->sequence;
        $pearlFirst = [];
        foreach ($ctx->tree->nodes as $id => $_) {
            $id = (string) $id;
            $n = 0;
            foreach ($ctx->tree->childrenOf($id) as $c) {
                if ($ctx->eff[$c] >= $pearlSeq && $ctx->tree->isMember($c)) {
                    $n++;
                }
            }
            $pearlFirst[$id] = $n;
        }

        foreach ($movements as $m) {
            if ($m->reversesId !== null && isset($ctx->prior->originalMovements[$m->reversesId])) {
                array_push($lines, ...(ClawbackCalculator::calculate($ctx, $m) ?? []));

                continue;
            }
            if ($m->excluded) {
                $ctx->warnings[] = "Movimento {$m->id} escluso dai bonus";

                continue;
            }
            if (! self::isCommissionable($ctx->plan, $m)) {
                continue; // acquisti personali: nessun compenso se la politica kosmos_own_purchase_as_client è spenta
            }
            if ($m->kind === 'pvf' && $m->customerId === null) {
                $ctx->warnings[] = "Movimento {$m->id}: cliente non identificato, non conta per scaglioni e requisiti";
            }
            // V.A.P.: deciso dal prodotto (percentuale o importo fisso); senza, la quota V.A.P. del piano sull'importo.
            $base = $m->vapEur !== null ? Num::money($m->vapEur) : Num::money(self::amountOf($ctx->plan, $m)->multipliedBy($cfg->vapShare));
            $o = $m->ownerId;

            // Compensi diretti
            $rate = $cfg->directRate($clients[$o] ?? 0);
            [$st, $why] = $ctx->directStatus($o);
            $lines[] = new Line(
                key: "direct:{$m->id}", bonus: 'direct', beneficiaryId: $o, amount: Num::money($base->multipliedBy($rate)),
                base: $base, rate: $rate, level: 0, sourceMovementId: $m->id, sourcePartnerId: $o, status: $st, reason: $why,
                explanation: [
                    'formula' => "{$base} × ".Num::pctLabel($rate).'%', 'clients' => $clients[$o] ?? 0,
                    'vap_share' => $m->vapEur !== null ? 'prodotto' : (string) $cfg->vapShare, 'volume_eur' => (string) self::amountOf($ctx->plan, $m),
                ],
                decisions: ['K01'],
            );

            // Compensi indiretti (livelli 1..N) e indiretti estesi
            $path = [];
            foreach ($ctx->tree->ancestors($o) as $u) {
                $path[] = $u;
            }
            $paid = 0;
            foreach ($path as $i => $u) {
                $level = $compress ? $paid + 1 : $i + 1;
                $levelDef = $cfg->level($level);
                if ($levelDef !== null) {
                    $rateL = $levelDef['rate'];
                    $ok = ($clients[$u] ?? 0) >= $levelDef['min_clients'] && $pearlFirst[$u] >= $levelDef['min_pearl'];
                    [$st, $why] = $ctx->indirectStatus($u);
                    if ($compress) {
                        // Compressione: chi non rispetta i requisiti (o non può maturare) è saltato e il livello passa al primo sopra di lui che li rispetta.
                        if (! $ok || $st === 'excluded') {
                            // Saltato: non incassa e il livello passa al primo upline sopra di lui che rispetta i requisiti.
                            // Si registra comunque una riga «non spettante» (mai pagata) così si vede perché non ha maturato.
                            $skipWhy = $st === 'excluded' ? $why : "requisiti_livello_{$level}: clienti ".($clients[$u] ?? 0).'/'.$levelDef['min_clients'].", Pearl 1° liv. {$pearlFirst[$u]}/{$levelDef['min_pearl']}";
                            $lines[] = new Line(
                                key: "indirect:{$m->id}:{$u}", bonus: 'indirect', beneficiaryId: $u, amount: Num::money($base->multipliedBy($rateL)),
                                base: $base, rate: $rateL, level: $level, sourceMovementId: $m->id, sourcePartnerId: $o, status: 'excluded', reason: $skipWhy,
                                explanation: [
                                    'formula' => "{$base} × ".Num::pctLabel($rateL).'%', 'rank' => $ctx->rankCode($u),
                                    'clients' => $clients[$u] ?? 0, 'min_clients' => $levelDef['min_clients'],
                                    'pearl_first_level' => $pearlFirst[$u], 'min_pearl' => $levelDef['min_pearl'],
                                    'compressed' => true, 'depth' => $i + 1,
                                ],
                                decisions: ['K01'],
                            );

                            continue;
                        }
                        $paid++;
                    }
                    if (! $ok) {
                        [$st, $why] = ['excluded', "requisiti_livello_{$level}: clienti ".($clients[$u] ?? 0).'/'.$levelDef['min_clients'].", Pearl 1° liv. {$pearlFirst[$u]}/{$levelDef['min_pearl']}"];
                    }
                    $lines[] = new Line(
                        key: "indirect:{$m->id}:{$u}", bonus: 'indirect', beneficiaryId: $u, amount: Num::money($base->multipliedBy($rateL)),
                        base: $base, rate: $rateL, level: $level, sourceMovementId: $m->id, sourcePartnerId: $o, status: $st, reason: $why,
                        explanation: [
                            'formula' => "{$base} × ".Num::pctLabel($rateL).'%', 'rank' => $ctx->rankCode($u),
                            'clients' => $clients[$u] ?? 0, 'min_clients' => $levelDef['min_clients'],
                            'pearl_first_level' => $pearlFirst[$u], 'min_pearl' => $levelDef['min_pearl'],
                        ],
                        decisions: ['K01'],
                    );

                    continue;
                }
                $level = $i + 1;
                $extRate = $cfg->extendedRates[$ctx->rankCode($u)] ?? null;
                if ($extRate === null || ! $extRate->isPositive()) {
                    continue;
                }
                // Si ferma al 5° livello del successivo con pari qualifica: bloccato se, tra u e chi vende,
                // c'è un tale incaricato a più di N livelli sopra il venditore.
                $blocked = false;
                $mySeq = $ctx->eff[$u];
                for ($j = 0; $j < $i; $j++) { // $path[$j] è a $j+1 livelli sopra il venditore
                    $w = $path[$j];
                    $same = $ctx->plan->policies->is('kosmos_extended_stop', 'same_or_higher') ? $ctx->eff[$w] >= $mySeq : $ctx->eff[$w] === $mySeq;
                    if ($same && ($j + 1) > $cfg->extendedStopLevels) {
                        $blocked = true;
                        break;
                    }
                }
                if ($blocked) {
                    continue;
                }
                [$st, $why] = $ctx->indirectStatus($u);
                $req = $cfg->extendedRequiresLevel === null ? null : $cfg->level($cfg->extendedRequiresLevel);
                if ($req !== null && ($st !== 'excluded') && (($clients[$u] ?? 0) < $req['min_clients'] || $pearlFirst[$u] < $req['min_pearl'])) {
                    [$st, $why] = ['excluded', "requisiti_livello_{$cfg->extendedRequiresLevel}"];
                }
                $lines[] = new Line(
                    key: "indirect_ext:{$m->id}:{$u}", bonus: 'indirect_ext', beneficiaryId: $u, amount: Num::money($base->multipliedBy($extRate)),
                    base: $base, rate: $extRate, level: $level, sourceMovementId: $m->id, sourcePartnerId: $o, status: $st, reason: $why,
                    explanation: ['formula' => "{$base} × ".Num::pctLabel($extRate).'%', 'rank' => $ctx->rankCode($u), 'level' => $level],
                    decisions: ['K02'],
                );
            }
        }

        array_push($lines, ...self::structureBonuses($ctx, $movements, $closedReversals, $firstPurchase));

        return $lines;
    }

    /**
     * Bonus di Struttura: l’incaricato che porta il N-esimo cliente entro la finestra dall'attivazione diventa PearlQ
     * e ogni upline riceve bonus(propria qualifica) − bonus(qualifica più alta tra il PearlQ e sé).
     *
     * @param  list<Movement>  $movements
     * @param  array<string, true>  $closedReversals
     * @param  array<string, array<string, DateTimeImmutable>>  $firstPurchase
     * @return list<Line>
     */
    private static function structureBonuses(BonusContext $ctx, array $movements, array $closedReversals, array $firstPurchase): array
    {
        $cfg = $ctx->plan->kosmos;
        $lines = [];
        foreach (self::mergedFirstPurchase($ctx->plan, $movements, $closedReversals, $firstPurchase) as $x => $customers) {
            if (! isset($ctx->tree->nodes[$x]) || ! $ctx->tree->isMember((string) $x)) {
                continue;
            }
            $dates = array_values($customers);
            sort($dates);
            if (count($dates) < $cfg->structureClients) {
                continue;
            }
            $reachedAt = $dates[$cfg->structureClients - 1];
            $since = $ctx->tree->nodes[$x]->since;
            if (! $ctx->period->contains($reachedAt) || $reachedAt > $since->modify("+{$cfg->structureWindowDays} days")) {
                continue;
            }
            $highest = $cfg->structureBonusFor($ctx->rankCode((string) $x));
            $path = [['partner' => (string) $x, 'rank' => $ctx->rankCode((string) $x), 'bonus' => (string) $highest]];
            foreach ($ctx->tree->ancestors((string) $x) as $u) {
                $own = $cfg->structureBonusFor($ctx->rankCode($u));
                $diff = $own->minus($highest);
                if ($diff->isPositive()) {
                    [$st, $why] = $ctx->indirectStatus($u);
                    $lines[] = new Line(
                        key: "structure:{$x}:{$u}", bonus: 'structure', beneficiaryId: $u, amount: Num::money($diff),
                        base: Num::money($own), sourcePartnerId: (string) $x, status: $st, reason: $why,
                        explanation: [
                            'formula' => "{$own} − {$highest}", 'pearlq' => (string) $x, 'reached_at' => $reachedAt->format('Y-m-d'),
                            'window_ends' => $since->modify("+{$cfg->structureWindowDays} days")->format('Y-m-d'), 'path' => $path,
                        ],
                        decisions: ['K03'],
                    );
                }
                $highest = Num::max($highest, $own);
                $path[] = ['partner' => $u, 'rank' => $ctx->rankCode($u), 'bonus' => (string) $own];
            }
        }

        return $lines;
    }
}
