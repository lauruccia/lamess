<?php

declare(strict_types=1);

namespace App\Domain\Career;

use App\Domain\Plan\LegacyPlan;
use App\Domain\Engine\Calc\PeriodCalculator;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Periods\SnapshotBuilder;
use App\Models\CalculationRun;
use App\Models\ConsultantPeriodStat;
use App\Models\Period;
use Illuminate\Support\Facades\DB;

/**
 * «Cosa manca per il livello successivo»: PR, struttura, attività e bonus in arrivo, per ogni incaricato.
 * Non ricalcola nulla di suo: legge l'ultimo calcolo del motore (il dettaglio `next_rank` è già nelle statistiche del
 * periodo); se il periodo non ha ancora un calcolo, ne esegue uno in memoria senza scrivere sul database.
 */
final class CareerProgress
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $cache = null;

    private ?string $periodCode = null;

    private string $source = '';

    /** @return array<string, mixed>|null */
    public function forConsultant(int $id): ?array
    {
        return $this->all()[$id] ?? null;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $period = Period::query()->orderByDesc('code')->first();
        if ($period === null) {
            return $this->cache = [];
        }
        $plan = $period->planVersion->plan();
        $stats = $this->stats($period);
        $this->periodCode = $period->code;

        $names = DB::table('consultants')->pluck('name', 'id')->all();
        $awards = DB::table('rank_awards')->get(['id', 'consultant_id', 'rank_code', 'amount', 'status', 'reason'])->groupBy('consultant_id');
        $installments = DB::table('rank_bonus_installments')->join('rank_awards', 'rank_awards.id', '=', 'rank_bonus_installments.rank_award_id')
            ->whereIn('rank_bonus_installments.status', ['due', 'held'])->orderBy('due_period')
            ->get(['rank_awards.consultant_id', 'rank_awards.rank_code', 'rank_bonus_installments.number', 'rank_bonus_installments.of', 'rank_bonus_installments.amount', 'rank_bonus_installments.due_period', 'rank_bonus_installments.status'])
            ->groupBy('consultant_id');

        $out = [];
        foreach ($stats as $id => $s) {
            try {
                $out[$id] = $this->build($id, $s, $plan, $names, $awards->get($id, collect())->all(), $installments->get($id, collect())->all());
            } catch (\Throwable $e) {
                report($e);   // un dato anomalo di un incaricato non deve bloccare gli altri
            }
        }

        return $this->cache = $out;
    }

    /** @return array{period: ?string, source: string} */
    public function meta(): array
    {
        $this->all();

        return ['period' => $this->periodCode, 'source' => $this->source];
    }

    /** @return array<int, array<string, mixed>> */
    private function stats(Period $period): array
    {
        $runId = $period->official_run_id ?? CalculationRun::query()->where('period_id', $period->id)->where('status', 'completed')->orderByDesc('id')->value('id');
        $out = [];
        if ($runId !== null) {
            $this->source = 'ultimo calcolo';
            foreach (ConsultantPeriodStat::query()->where('run_id', $runId)->cursor() as $s) {
                $out[(int) $s->consultant_id] = [
                    'achieved' => $s->achieved_rank, 'effective' => $s->effective_rank, 'active' => (bool) $s->is_active, 'reason' => (string) $s->active_reason,
                    'activity' => (float) $s->activity_points, 'group_pr' => (float) $s->group_pr_closing, 'personal_pr' => (float) $s->pr_closing,
                    'indirect' => (string) $s->indirect_status, 'matching' => (string) $s->matching_status, 'next' => (array) $s->next_rank,
                ];
            }

            return $out;
        }
        $this->source = 'calcolo di prova (nessun calcolo salvato)';
        $result = PeriodCalculator::calculate(SnapshotBuilder::build($period));
        foreach ($result->stats as $id => $s) {
            $out[(int) $id] = [
                'achieved' => $s->achievedRank, 'effective' => $s->effectiveRank, 'active' => $s->active, 'reason' => $s->activeReason,
                'activity' => (float) (string) $s->activityPoints, 'group_pr' => (float) (string) $s->groupPrClosing, 'personal_pr' => (float) (string) $s->personalPrClosing,
                'indirect' => $s->indirectStatus, 'matching' => $s->matchingStatus, 'next' => $s->nextRank,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  array<int, string>  $names
     * @param  list<object>  $awards
     * @param  list<object>  $installments
     * @return array<string, mixed>
     */
    private function build(int $id, array $s, PlanConfig $plan, array $names, array $awards, array $installments): array
    {
        if ($plan->kosmos !== null) {
            return $this->buildKosmos($id, $s, $plan);
        }
        $rank = $plan->rank($s['achieved']);
        $nextDef = $plan->hasSeq($rank->sequence + 1) ? $plan->bySeq($rank->sequence + 1) : null;
        $d = $s['next'];
        $thr = (float) (string) $plan->activityThreshold;
        $todo = [];
        $legacy = LegacyPlan::visible();   // vecchio piano nascosto: niente PR, Direct di qualifica, Rank Bonus, Matching, rate

        $hasActiveStatus = $plan->kosmos === null; // il piano Incaricati non ha lo stato ATTIVO
        $activity = ['applies' => $hasActiveStatus, 'points' => $s['activity'], 'threshold' => $thr, 'missing' => max(0.0, $thr - $s['activity']), 'active' => $s['active'], 'reason' => $s['reason']];
        $blocked = preg_match('/sospes|cessat/i', $s['reason']) === 1;
        if (! $s['active'] && $blocked) {
            $todo[] = 'Non risulti attivo: '.mb_strtolower($s['reason']).'.';
        } elseif ($legacy && $hasActiveStatus && ! $s['active'] && $activity['missing'] > 0) {
            $todo[] = 'Per risultare attivo questo mese ti mancano '.self::n($activity['missing']).' PR (soglia '.self::n($thr).').';
        }

        $next = null;
        $pr = ['required' => 0.0, 'counted' => 0.0, 'missing' => 0.0, 'percent' => 100, 'personal' => $s['personal_pr'], 'group' => $s['group_pr']];
        $fifty = null;
        $structure = ['required' => [], 'missing' => [], 'ok' => true];
        if ($nextDef !== null && $d !== []) {
            $required = (float) ($d['pr_required'] ?? 0);
            $counted = (float) ($d['pr_counted'] ?? 0);
            $pr = [
                'required' => $required, 'counted' => $counted, 'missing' => max(0.0, $required - $counted),
                'percent' => $required > 0 ? (int) min(100, floor($counted / $required * 100)) : 100, 'personal' => (float) ($d['personal_pr'] ?? 0), 'group' => (float) ($d['pr_group'] ?? 0),
            ];
            $installable = $nextDef->rankBonus->isGreaterThan($plan->rankBonusInstallmentThreshold);
            $next = [
                'code' => $nextDef->code, 'name' => $nextDef->name, 'direct_pct' => (float) (string) $nextDef->directRate * 100, 'current_pct' => (float) (string) $rank->directRate * 100,
                'rank_bonus' => (float) (string) $nextDef->rankBonus, 'installments' => $installable ? $plan->rankBonusInstallments : 1,
            ];
            if (isset($d['line_cap'])) {
                $cap = (float) $d['line_cap'];
                $lines = [];
                foreach ((array) ($d['lines'] ?? []) as $lid => $l) {
                    $lines[] = ['id' => (int) $lid, 'name' => $names[(int) $lid] ?? '—', 'pr' => (float) $l['pr'], 'counted' => (float) $l['counted'], 'capped' => (float) $l['pr'] > $cap, 'rank' => $l['best_rank'] ? $plan->rank($l['best_rank'])->name : null];
                }
                usort($lines, static fn ($a, $b) => $b['pr'] <=> $a['pr']);
                $fifty = ['cap' => $cap, 'lines' => $lines, 'capped' => count(array_filter($lines, static fn ($l) => $l['capped']))];
            }
            $codeName = static fn (string $c) => $plan->rank($c)->name;
            $req = array_map($codeName, (array) ($d['structure_required'] ?? []));
            $missing = array_map($codeName, (array) ($d['structure_missing'] ?? []));
            $structure = ['required' => $req, 'missing' => $missing, 'ok' => (bool) ($d['structure_ok'] ?? true)];

            if ($legacy && $pr['missing'] > 0) {
                $todo[] = "Per {$next['name']} ti mancano ".self::n($pr['missing']).' PR di gruppo (ne hai '.self::n($pr['counted']).' su '.self::n($pr['required']).').';
            }
            if ($legacy && $fifty !== null && $fifty['capped'] > 0) {
                $todo[] = 'Regola del 50%: ogni linea conta al massimo '.self::n($fifty['cap']).' PR; '.$fifty['capped'].($fifty['capped'] === 1 ? ' tua linea ha' : ' tue linee hanno').' già raggiunto il tetto, i PR in più vanno costruiti in altre linee.';
            }
            if ($missing !== []) {
                $todo[] = 'Struttura richiesta: '.self::group($missing).'.';
            }
            if (($legacy ? $pr['missing'] <= 0 : true) && $structure['ok']) {
                $todo[] = $legacy
                    ? "Requisiti per {$next['name']} soddisfatti: la qualifica è effettiva dal 1° del mese successivo al raggiungimento."
                    : "Requisiti per {$next['name']} soddisfatti.";
            }
            if ($legacy && $next['rank_bonus'] > 0) {
                $todo[] = "Raggiunta {$next['name']} avrai un Rank Bonus di € ".self::n($next['rank_bonus']).($next['installments'] > 1 ? " in {$next['installments']} rate" : ' una tantum').'.';
            }
            if ($legacy && $next['direct_pct'] > $next['current_pct']) {
                $todo[] = 'La percentuale Direct sale dal '.self::n($next['current_pct']).'% al '.self::n($next['direct_pct']).'%.';
            }
        } else {
            $todo[] = 'Hai raggiunto la qualifica massima del piano.';
        }

        $gates = [];
        foreach (['indirect' => ['Bonus Indiretti', $plan->indirectMinRank], 'matching' => ['Matching', $plan->matchingMinRank]] as $k => [$label, $code]) {
            $need = $plan->rank($code);
            if (! $legacy && $k === 'matching') {
                continue;
            }
            $reached = $rank->sequence >= $need->sequence;
            $gates[$k] = ['label' => $label, 'rank' => $need->name, 'reached' => $reached, 'status' => $s[$k]];
            if (! $reached && $nextDef !== null && $need->sequence === $nextDef->sequence) {
                $todo[] = "Con {$need->name} si sblocca: {$label}.";
            } elseif (! $reached) {
                $todo[] = "{$label}: disponibile dalla qualifica {$need->name}.";
            }
        }

        $bonuses = [
            'installments' => array_map(static fn ($i) => ['rank' => $plan->rank($i->rank_code)->name, 'number' => (int) $i->number, 'of' => (int) $i->of, 'amount' => (float) $i->amount, 'due' => $i->due_period, 'status' => $i->status], $installments),
            'awards' => array_map(static fn ($a) => ['rank' => $plan->rank($a->rank_code)->name, 'amount' => (float) $a->amount, 'status' => $a->status, 'reason' => (string) $a->reason], $awards),
        ];
        if (! $legacy) {
            $bonuses = ['installments' => [], 'awards' => []];
        }
        foreach ($bonuses['installments'] as $i) {
            $todo[] = "Rata Rank Bonus {$i['rank']} {$i['number']}/{$i['of']}: € ".self::n($i['amount']).' in scadenza '.$i['due'].($i['status'] === 'held' ? ' (trattenuta)' : '').'.';
        }

        return [
            'consultant_id' => $id, 'rank' => ['code' => $rank->code, 'name' => $rank->name, 'sequence' => $rank->sequence, 'direct_pct' => (float) (string) $rank->directRate * 100],
            'effective_rank' => $plan->rank($s['effective'])->name, 'next' => $next, 'pr' => $pr, 'fifty' => $fifty, 'structure' => $structure,
            'activity' => $activity, 'legacy_hidden' => ! $legacy, 'gates' => $gates, 'bonuses' => $bonuses, 'todo' => $todo,
        ];
    }

    /**
     * Piano Incaricati: nessun PR, nessuno stato attivo. Contano clienti personali, Pearl al 1° livello, linee e colonne.
     * Restituisce le stesse chiavi del piano a PR (così le schermate non si rompono) più il blocco «kosmos».
     *
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function buildKosmos(int $id, array $s, PlanConfig $plan): array
    {
        $cfg = $plan->kosmos;
        $d = (array) $s['next'];
        $rank = $plan->rank($s['achieved']);
        $effective = $plan->rank($s['effective']);
        $nextDef = $plan->hasSeq($rank->sequence + 1) ? $plan->bySeq($rank->sequence + 1) : null;
        $clients = isset($d['personal_clients']) ? (int) $d['personal_clients'] : (isset($d['clients_counted']) ? (int) $d['clients_counted'] : null);
        $pearl = isset($d['pearl_first_level_now']) ? (int) $d['pearl_first_level_now'] : (isset($d['pearl_first_level_counted']) ? (int) $d['pearl_first_level_counted'] : null);
        $c = $clients ?? 0;
        $p = $pearl ?? 0;
        $known = $clients !== null;
        $todo = [];
        if (preg_match('/sospes|cessat/i', (string) $s['reason']) === 1) {
            $todo[] = 'Il tuo account risulta '.mb_strtolower((string) $s['reason']).': i compensi indiretti sono sospesi.';
        }

        // Compensi diretti: scaglioni per numero di clienti.
        $tiers = [];
        $current = null;
        $nextTier = null;
        foreach ($cfg->directTiers as $t) {
            $reached = $known && $c >= $t['min_clients'];
            $row = ['min_clients' => $t['min_clients'], 'pct' => (float) (string) $t['rate'] * 100, 'reached' => $reached, 'current' => false, 'missing' => max(0, $t['min_clients'] - $c)];
            if ($reached) {
                $current = count($tiers);
            } elseif ($nextTier === null) {
                $nextTier = count($tiers);
            }
            $tiers[] = $row;
        }
        if ($current !== null) {
            $tiers[$current]['current'] = true;
        }
        if ($known && $nextTier !== null) {
            $curPct = $current !== null ? $tiers[$current]['pct'] : 0.0;
            $todo[] = 'Con '.$tiers[$nextTier]['missing'].($tiers[$nextTier]['missing'] === 1 ? ' cliente in più' : ' clienti in più').' il compenso diretto sale dal '.self::n($curPct).'% al '.self::n($tiers[$nextTier]['pct']).'%.';
        }

        // Compensi indiretti per livello.
        $levels = [];
        $firstLocked = null;
        foreach ($cfg->indirectLevels as $l) {
            $okC = $c >= $l['min_clients'];
            $okP = $p >= $l['min_pearl'];
            $row = [
                'level' => $l['level'], 'pct' => (float) (string) $l['rate'] * 100, 'min_clients' => $l['min_clients'], 'min_pearl' => $l['min_pearl'],
                'unlocked' => $known && $okC && $okP, 'missing_clients' => max(0, $l['min_clients'] - $c), 'missing_pearl' => max(0, $l['min_pearl'] - $p),
            ];
            if (! $row['unlocked'] && $firstLocked === null) {
                $firstLocked = count($levels);
            }
            $levels[] = $row;
        }
        if ($known && $firstLocked !== null) {
            $lv = $levels[$firstLocked];
            $need = [];
            if ($lv['missing_clients'] > 0) {
                $need[] = $lv['missing_clients'].($lv['missing_clients'] === 1 ? ' cliente' : ' clienti');
            }
            if ($lv['missing_pearl'] > 0) {
                $need[] = $lv['missing_pearl'].' Pearl al 1° livello';
            }
            $todo[] = "Per incassare il livello {$lv['level']} ({$lv['pct']}%) ti ".(count($need) > 1 || $lv['missing_clients'] > 1 || $lv['missing_pearl'] > 1 ? 'mancano ' : 'manca ').implode(' e ', $need).'.';
        }

        // Indiretti estesi: qualifiche che li sbloccano.
        $extended = [];
        foreach ($cfg->extendedRates as $code => $rate) {
            if ($rate->isPositive() && $plan->hasCode($code)) {
                $extended[] = ['rank' => $plan->rank($code)->name, 'pct' => (float) (string) $rate * 100, 'active' => $rank->sequence >= $plan->rank($code)->sequence];
            }
        }

        // Requisiti per la qualifica successiva (e scala di tutte le qualifiche).
        $requirements = $nextDef !== null ? $this->kosmosRequirements($plan, $nextDef, $d) : [];
        $missingItems = array_values(array_filter($requirements, static fn ($r) => ! $r['ok'] && ! $r['disabled']));
        if ($nextDef !== null) {
            foreach ($missingItems as $r) {
                $todo[] = $r['todo'];
            }
            if ($missingItems === [] && $requirements !== []) {
                $todo[] = "Requisiti per {$nextDef->name} soddisfatti: la qualifica viene riconosciuta al prossimo calcolo.";
            }
            $bonus = $cfg->structureBonusFor($nextDef->code);
            if ($bonus->isPositive()) {
                $todo[] = "Con {$nextDef->name} il Bonus di Struttura sale a € ".self::n((float) (string) $bonus).'.';
            }
        } else {
            $todo[] = 'Hai raggiunto la qualifica massima del piano.';
        }
        $done = count(array_filter($requirements, static fn ($r) => $r['ok'] || $r['disabled']));
        $percent = $requirements === [] ? 100 : (int) floor($done / count($requirements) * 100);

        // Mantenimento della qualifica in corso.
        $maintenance = null;
        if ($plan->policies->is('kosmos_rank_maintenance', 'clients') && $rank->requires('clients') && $rank->minClients > 0) {
            $maintenance = [
                'rank' => $rank->name, 'required' => $rank->minClients, 'counted' => $c, 'ok' => $known && $c >= $rank->minClients,
                'protected_until' => $cfg->rankGraceEnd(),
            ];
            if ($known && ! $maintenance['ok']) {
                $todo[] = "Per mantenere {$rank->name} servono almeno {$rank->minClients} clienti: ne hai {$c}.".($maintenance['protected_until'] ? ' Fino al '.date('d/m/Y', (int) strtotime($maintenance['protected_until'])).' la qualifica è comunque protetta.' : '');
            }
        }

        $ladder = [];
        for ($i = 0; $plan->hasSeq($i); $i++) {
            $r = $plan->bySeq($i);
            $ladder[] = [
                'name' => $r->name, 'sequence' => $r->sequence,
                'state' => $r->sequence < $rank->sequence ? 'done' : ($r->sequence === $rank->sequence ? 'current' : ($nextDef !== null && $r->sequence === $nextDef->sequence ? 'next' : 'future')),
                'requirements' => $this->rankRequirementTexts($plan, $r),
                'structure_bonus' => (float) (string) $cfg->structureBonusFor($r->code),
                'extended_pct' => isset($cfg->extendedRates[$r->code]) ? (float) (string) $cfg->extendedRates[$r->code] * 100 : 0.0,
            ];
        }

        $rule = $cfg->clientRule === \App\Domain\Engine\Plan\KosmosConfig::CLIENT_ANY_ORDER ? 'chi fa almeno un ordine' : 'chi fa almeno un ordine da € '.self::n((float) (string) $cfg->clientMinAmount).' (IVA inclusa)';

        return [
            'consultant_id' => $id, 'rank' => ['code' => $rank->code, 'name' => $rank->name, 'sequence' => $rank->sequence, 'direct_pct' => $current !== null ? $tiers[$current]['pct'] : 0.0],
            'effective_rank' => $effective->name, 'next' => $nextDef === null ? null : [
                'code' => $nextDef->code, 'name' => $nextDef->name, 'direct_pct' => 0.0, 'current_pct' => 0.0, 'rank_bonus' => 0.0, 'installments' => 1,
            ],
            'pr' => ['required' => 0.0, 'counted' => 0.0, 'missing' => 0.0, 'percent' => $percent, 'personal' => 0.0, 'group' => 0.0], 'fifty' => null,
            'structure' => ['required' => [], 'missing' => [], 'ok' => true],
            'activity' => ['applies' => false, 'points' => 0.0, 'threshold' => 0.0, 'missing' => 0.0, 'active' => $s['active'], 'reason' => $s['reason']],
            'legacy_hidden' => true, 'gates' => [], 'bonuses' => ['installments' => [], 'awards' => []], 'todo' => $todo,
            'kosmos' => [
                'period' => $this->periodCode, 'known' => $known, 'clients' => $clients, 'pearl' => $pearl, 'client_rule' => $rule,
                'tiers' => $tiers, 'levels' => $levels, 'extended' => $extended, 'requirements' => $requirements, 'percent' => $percent,
                'maintenance' => $maintenance, 'ladder' => $ladder, 'next_name' => $nextDef?->name,
                'indirect_status' => $s['indirect'], 'suspended' => preg_match('/sospes|cessat/i', (string) $s['reason']) === 1,
            ],
        ];
    }

    /**
     * Requisiti della qualifica successiva con valore richiesto, raggiunto ed esito.
     *
     * @param  array<string, mixed>  $d  dettaglio salvato dal motore
     * @return list<array{key:string,label:string,required:int,counted:int,ok:bool,disabled:bool,detail:string,todo:string}>
     */
    private function kosmosRequirements(PlanConfig $plan, \App\Domain\Engine\Plan\RankDef $next, array $d): array
    {
        $out = [];
        if ($next->minClients > 0) {
            $counted = (int) ($d['clients_counted'] ?? 0);
            $dis = ! $next->requires('clients');
            $miss = max(0, $next->minClients - $counted);
            $out[] = ['key' => 'clients', 'label' => 'Clienti personali', 'required' => $next->minClients, 'counted' => $counted, 'ok' => $miss === 0, 'disabled' => $dis, 'detail' => '',
                'todo' => "Per {$next->name} ti ".($miss === 1 ? 'manca 1 cliente personale' : "mancano {$miss} clienti personali")." (ne hai {$counted} su {$next->minClients})."];
        }
        if ($next->minPearlFirstLevel > 0) {
            $counted = (int) ($d['pearl_first_level_counted'] ?? 0);
            $dis = ! $next->requires('pearl');
            $miss = max(0, $next->minPearlFirstLevel - $counted);
            $out[] = ['key' => 'pearl', 'label' => 'Diretti Pearl (1° livello)', 'required' => $next->minPearlFirstLevel, 'counted' => $counted, 'ok' => $miss === 0, 'disabled' => $dis, 'detail' => 'Incaricati portati da te con qualifica Pearl o superiore',
                'todo' => "Per {$next->name} ti ".($miss === 1 ? 'manca 1 incaricato diretto Pearl' : "mancano {$miss} incaricati diretti Pearl").' al 1° livello.'];
        }
        $reqCodes = (array) ($d['structure_required'] ?? []);
        if ($reqCodes !== []) {
            $names = array_map(static fn (string $c) => $plan->rank($c)->name, $reqCodes);
            $missing = array_map(static fn (string $c) => $plan->rank($c)->name, (array) ($d['structure_missing'] ?? []));
            $dis = ! $next->requires('structure');
            $out[] = ['key' => 'structure', 'label' => 'Struttura su linee diverse', 'required' => count($names), 'counted' => count($names) - count($missing), 'ok' => $missing === [], 'disabled' => $dis,
                'detail' => 'Richiesto: '.implode(', ', $names).($missing !== [] ? ' · mancano: '.implode(', ', $missing) : ''),
                'todo' => 'Struttura richiesta per '.$next->name.': '.self::group($missing).'.'];
        }
        if ($next->minColumns > 0 && $next->minColumnClients > 0) {
            $counted = (int) ($d['columns_counted'] ?? 0);
            $dis = ! $next->requires('columns');
            $miss = max(0, $next->minColumns - $counted);
            $out[] = ['key' => 'columns', 'label' => "Colonne da {$next->minColumnClients} clienti", 'required' => $next->minColumns, 'counted' => $counted, 'ok' => $miss === 0, 'disabled' => $dis, 'target' => $next->minColumnClients,
                'detail' => "Una colonna è un tuo diretto con tutta la sua discendenza: servono {$next->minColumnClients} clienti in totale in ciascuna (esclusi i tuoi personali)",
                'todo' => "Per {$next->name} ti ".($miss === 1 ? 'manca 1 colonna' : "mancano {$miss} colonne")." da {$next->minColumnClients} clienti."];
        }

        return $out;
    }

    /** @return list<string> */
    private function rankRequirementTexts(PlanConfig $plan, \App\Domain\Engine\Plan\RankDef $r): array
    {
        $t = [];
        if ($r->minClients > 0 && $r->requires('clients')) {
            $t[] = $r->minClients.' clienti personali';
        }
        if ($r->minPearlFirstLevel > 0 && $r->requires('pearl')) {
            $t[] = $r->minPearlFirstLevel.' Pearl al 1° livello';
        }
        if ($r->requires('structure')) {
            foreach ($r->structure as $sr) {
                $t[] = $sr->count.($sr->count === 1 ? ' linea ' : ' linee ').$plan->rank($sr->rankCode)->name;
            }
        }
        if ($r->minColumns > 0 && $r->minColumnClients > 0 && $r->requires('columns')) {
            $t[] = $r->minColumns.($r->minColumns === 1 ? ' colonna' : ' colonne').' da '.$r->minColumnClients.' clienti';
        }

        return $t;
    }

    private static function n(float $v): string
    {
        return number_format($v, fmod($v, 1.0) === 0.0 ? 0 : 2, ',', '.');
    }

    /** @param list<string> $names */
    private static function group(array $names): string
    {
        $c = array_count_values($names);
        $parts = [];
        foreach ($c as $n => $k) {
            $parts[] = $k === 1 ? "1 linea {$n}" : "{$k} linee {$n}";
        }

        return 'mancano '.implode(', ', $parts);
    }
}
