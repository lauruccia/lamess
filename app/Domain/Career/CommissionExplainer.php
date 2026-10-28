<?php

declare(strict_types=1);

namespace App\Domain\Career;

use App\Domain\Payouts\StatementService;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Consultant;
use App\Models\Order;
use App\Models\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * «Da cosa è dovuto ogni compenso»: per un incaricato e un mese, ogni riga di commissione (dell'ultimo calcolo)
 * con una frase in italiano che dice quale ordine l'ha generata, di chi, a che livello, con quale regola e perché
 * è maturata, trattenuta o non spettante. Sola lettura: non ricalcola nulla, legge ciò che il motore ha salvato.
 */
final class CommissionExplainer
{
    /** Ordine con cui si mostrano i gruppi. */
    private const ORDER = ['direct', 'indirect', 'indirect_ext', 'structure', 'clawback', 'team', 'matching', 'rank', 'rank_installment', 'fsb'];

    /**
     * @return array{
     *   consultant: array{id:int,code:string,name:string},
     *   period: Period, run_id: ?int, closed: bool,
     *   groups: list<array{type:string,label:string,accrued:float,held:float,lines:list<array<string,mixed>>}>,
     *   missing: array{total:float, levels:list<array<string,mixed>>, other:list<array<string,mixed>>, lines:list<array<string,mixed>>},
     *   accrued: float, held: float, excluded: float, rules: list<string>
     * }
     */
    public function for(Consultant $consultant, Period $period): array
    {
        $runId = $period->official_run_id ?? DB::table('calculation_runs')->where('period_id', $period->id)->where('status', 'completed')->max('id');
        $runId = $runId === null ? null : (int) $runId;

        $lines = $runId === null ? collect() : DB::table('commission_lines as cl')
            ->leftJoin('pv_movements as m', 'm.id', '=', 'cl.source_movement_id')
            ->leftJoin('orders as o', 'o.id', '=', 'm.order_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.client_user_id')
            ->leftJoin('consultants as s', 's.id', '=', 'cl.source_consultant_id')
            ->where('cl.run_id', $runId)->where('cl.beneficiary_id', $consultant->id)->where('cl.bonus_type', '!=', 'structure')
            ->orderBy('cl.bonus_type')->orderByDesc('cl.id')
            ->get([
                'cl.id', 'cl.bonus_type', 'cl.base_amount', 'cl.pct', 'cl.amount', 'cl.level', 'cl.engine_status', 'cl.reason', 'cl.explanation',
                'cl.source_movement_id', 'cl.source_consultant_id',
                'm.id as mov_id', 'm.kind as mov_kind', 'm.type as mov_type', 'm.event_at', 'm.amount as mov_amount', 'm.order_id', 'm.external_ref',
                'o.number as order_number', 'u.name as buyer_name', 's.name as source_name', 's.code as source_code',
            ]);

        $sponsors = DB::table('consultants')->pluck('sponsor_id', 'id');
        $names = DB::table('consultants')->pluck('name', 'id');
        $kosmos = $this->kosmos($period);

        $rows = [];
        foreach ($lines as $l) {
            $rows[] = $this->explainLine($l, (int) $consultant->id, $sponsors->all(), $names->all(), $kosmos);
        }
        foreach ($this->structureRows($consultant, $period) as $r) {
            $rows[] = $r;
        }

        $missingRows = array_values(array_filter($rows, static fn (array $r) => $r['status'] === 'excluded'));
        $rows = array_values(array_filter($rows, static fn (array $r) => $r['status'] !== 'excluded'));

        $groups = [];
        foreach ($rows as $r) {
            $g = &$groups[$r['type']];
            $g ??= ['type' => $r['type'], 'label' => StatementService::LABELS[$r['type']] ?? $r['type'], 'accrued' => 0.0, 'held' => 0.0, 'lines' => []];
            $g[$r['status'] === 'held' ? 'held' : 'accrued'] += $r['amount'];
            $g['lines'][] = $r;
            unset($g);
        }
        uksort($groups, static function (string $a, string $b): int {
            $ia = array_search($a, self::ORDER, true);
            $ib = array_search($b, self::ORDER, true);

            return ($ia === false ? 99 : $ia) <=> ($ib === false ? 99 : $ib);
        });
        $groups = array_values($groups);

        return [
            'consultant' => ['id' => (int) $consultant->id, 'code' => (string) $consultant->code, 'name' => (string) $consultant->name],
            'period' => $period, 'run_id' => $runId, 'closed' => $period->status === Period::CLOSED,
            'groups' => $groups,
            'accrued' => array_sum(array_column($groups, 'accrued')), 'held' => array_sum(array_column($groups, 'held')),
            'excluded' => array_sum(array_column($missingRows, 'amount')),
            'missing' => $this->missing($missingRows),
            'rules' => $this->rules($kosmos),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $sponsors
     * @param  array<int|string, mixed>  $names
     * @return array<string, mixed>
     */
    private function explainLine(object $l, int $beneficiaryId, array $sponsors, array $names, ?\App\Domain\Engine\Plan\KosmosConfig $kosmos): array
    {
        $ex = is_string($l->explanation) ? (json_decode($l->explanation, true) ?: []) : [];
        $pct = $l->pct === null ? null : (float) $l->pct;
        $pctLabel = $pct === null ? null : $this->pct($pct);
        $amount = (float) $l->amount;
        $base = (float) $l->base_amount;
        $details = [];

        $src = $l->source_name !== null ? "{$l->source_name} [{$l->source_code}]" : null;
        $when = $l->event_at ? Carbon::parse($l->event_at, 'UTC')->setTimezone('Europe/Rome')->format('d/m/Y') : null;
        $volume = $l->mov_amount !== null ? (float) $l->mov_amount : (isset($ex['volume_eur']) ? (float) $ex['volume_eur'] : null);

        // Da quale ordine nasce la riga.
        if ($l->mov_id === null) {
            $origin = 'un movimento che non esiste più';
        } elseif ($l->order_number !== null) {
            $origin = 'l\'ordine '.$l->order_number.($when ? " del {$when}" : '').($volume !== null ? ', '.$this->eur($volume).' IVA inclusa' : '');
        } elseif ($l->mov_type === 'refund') {
            $origin = "il reso (movimento #{$l->mov_id})".($when ? " del {$when}" : '').($volume !== null ? ', '.$this->eur($volume) : '');
        } else {
            $origin = "il movimento #{$l->mov_id}".($when ? " del {$when}" : '').($volume !== null ? ', '.$this->eur($volume).' IVA inclusa' : '').' — NESSUN ORDINE COLLEGATO (inserito a mano o ordine eliminato)';
        }
        $orderUrl = $l->order_id !== null && Order::query()->whereKey($l->order_id)->exists() ? OrderResource::getUrl('view', ['record' => $l->order_id]) : null;
        $warning = $l->mov_id !== null && $l->order_id === null && $l->mov_type !== 'refund' && $l->mov_type !== 'adjustment';

        $baseTxt = ($volume !== null && $volume > 0 && $kosmos !== null)
            ? 'base '.$this->eur($base).' ('.$this->pct((float) $kosmos->vapShare->toFloat()).' di '.$this->eur($volume).')'
            : 'base '.$this->eur($base);

        switch ($l->bonus_type) {
            case 'direct':
                $own = $l->mov_kind === 'pvc';
                $title = $own ? 'Compenso diretto su acquisto personale' : 'Compenso diretto su vendita a un cliente';
                $who = $own ? 'L\'incaricato ha acquistato per sé' : ($l->buyer_name ? "{$l->buyer_name} ha acquistato" : 'Un cliente personale ha acquistato');
                $sentence = "{$who}: {$origin}. Compenso {$pctLabel} su {$baseTxt} = ".$this->eur($amount).'.';
                if ($own) {
                    $details[] = 'L\'acquisto dell\'incaricato conta come cliente di sé stesso (regola del piano).';
                }
                $clients = isset($ex['clients']) ? (int) $ex['clients'] : null;
                if ($clients !== null) {
                    $details[] = "Scaglione {$pctLabel}: l'incaricato ha ".$this->plural($clients, 'cliente personale', 'clienti personali').' nel mese.'.$this->nextTier($kosmos, $clients);
                }
                break;

            case 'indirect':
                $level = (int) $l->level;
                $title = "Compenso indiretto — livello {$level}";
                $sentence = ($src ? "{$src} ha generato" : 'Un incaricato della rete ha generato').": {$origin}".($l->buyer_name ? " (cliente {$l->buyer_name})" : '').". Livello {$level} del piano = {$pctLabel} su {$baseTxt} = ".$this->eur($amount).'.';
                $this->pathDetails($details, (int) $l->source_consultant_id, $beneficiaryId, $level, $sponsors, $names);
                if (isset($ex['min_clients'])) {
                    $details[] = "Requisiti del livello {$level}: clienti personali ".(int) ($ex['clients'] ?? 0).'/'.(int) $ex['min_clients'].', Pearl al 1° livello '.(int) ($ex['pearl_first_level'] ?? 0).'/'.(int) ($ex['min_pearl'] ?? 0).'.';
                }
                break;

            case 'indirect_ext':
                $level = (int) $l->level;
                $title = 'Indiretto esteso (oltre il 5° livello)';
                $sentence = ($src ? "{$src} ha generato" : 'Un incaricato della rete ha generato').": {$origin}. Indiretto esteso {$pctLabel} su {$baseTxt} = ".$this->eur($amount).'.';
                $details[] = 'Compenso riconosciuto a '.($ex['rank'] ?? 'Ruby/Emerald/Diamond')." per il livello {$level}, oltre il livello 5, fino al 5° livello del successivo con la stessa qualifica.";
                $this->pathDetails($details, (int) $l->source_consultant_id, $beneficiaryId, $level, $sponsors, $names);
                break;

            case 'clawback':
                $title = 'Storno per reso';
                $sentence = "Reso su un ordine di un mese già chiuso: il compenso «".(StatementService::LABELS[$ex['original_bonus'] ?? ''] ?? ($ex['original_bonus'] ?? '?'))."» del periodo ".($ex['original_period'] ?? '?').' viene ridotto di '.$this->eur(abs($amount)).'.';
                if (isset($ex['formula'])) {
                    $details[] = 'Calcolo: '.$ex['formula'];
                }
                break;

            default:
                $title = StatementService::LABELS[$l->bonus_type] ?? (string) $l->bonus_type;
                $sentence = ($src ? "Da {$src}: " : '').ucfirst($origin).($pctLabel ? ". {$pctLabel} su {$baseTxt}" : '').' = '.$this->eur($amount).'.';
                if (isset($ex['formula'])) {
                    $details[] = 'Formula: '.$ex['formula'];
                }
        }

        $miss = null;
        if ($l->engine_status === 'excluded' && $l->bonus_type === 'indirect' && isset($ex['min_clients'])) {
            $c = (int) ($ex['clients'] ?? 0);
            $p = (int) ($ex['pearl_first_level'] ?? 0);
            $minC = (int) $ex['min_clients'];
            $minP = (int) ($ex['min_pearl'] ?? 0);
            $lackC = max(0, $minC - $c);
            $lackP = max(0, $minP - $p);
            $lack = [];
            if ($lackC > 0) {
                $lack[] = 'mancano '.$this->plural($lackC, 'cliente personale', 'clienti personali')." (ne ha {$c}, ne servono {$minC})";
            }
            if ($lackP > 0) {
                $lack[] = "mancano {$lackP} Pearl al 1° livello (ne ha {$p}, ne servono {$minP})";
            }
            $miss = ['level' => (int) $l->level, 'pct' => $pctLabel, 'clients' => $c, 'min_clients' => $minC, 'pearl' => $p, 'min_pearl' => $minP, 'lack_clients' => $lackC, 'lack_pearl' => $lackP];
            if ($lack !== []) {
                $title = "Non maturato — livello {$l->level}: requisiti mancanti";
                $sentence = ($src ? "{$src} ha generato" : 'Un incaricato della rete ha generato').": {$origin}. Il livello {$l->level} varrebbe {$pctLabel} su {$baseTxt} = ".$this->eur($amount).', ma non matura: '.implode(' e ', $lack).'.';
                $details = array_values(array_filter($details, static fn (string $t) => ! str_starts_with($t, 'Requisiti del livello')));
            }
        }
        $statusLabel = match ($l->engine_status) {
            'accrued' => 'Maturato', 'held' => 'Trattenuto', default => 'Non spettante',
        };
        if ($l->engine_status !== 'accrued') {
            $details[] = $this->why((string) $l->engine_status, (string) $l->reason, $ex);
        }
        if ($warning) {
            $details[] = 'Attenzione: questo compenso viene da un movimento senza ordine. Se l\'ordine è stato eliminato, annulla o elimina anche il movimento da «Movimenti PV» e rilancia «Stima (live)».';
        }

        return [
            'id' => (int) $l->id, 'type' => (string) $l->bonus_type, 'date' => $when, 'title' => $title, 'sentence' => $sentence, 'details' => array_values(array_filter($details)),
            'order_number' => $l->order_number, 'order_url' => $orderUrl, 'movement_id' => $l->mov_id !== null ? (int) $l->mov_id : null,
            'amount' => $amount, 'base' => $base, 'pct' => $pctLabel, 'level' => $l->level !== null ? (int) $l->level : null,
            'status' => (string) $l->engine_status, 'status_label' => $statusLabel, 'warning' => $warning, 'missing' => $miss,
        ];
    }

    /**
     * «Compensi non maturati»: per livello, quanto sarebbe spettato e che cosa manca per sbloccarlo.
     *
     * @param  list<array<string, mixed>>  $rows  righe non spettanti
     * @return array{total:float, levels:list<array<string,mixed>>, other:list<array<string,mixed>>, lines:list<array<string,mixed>>}
     */
    private function missing(array $rows): array
    {
        $levels = [];
        $other = [];
        foreach ($rows as $r) {
            if ($r['missing'] !== null) {
                $m = $r['missing'];
                $k = $m['level'];
                $lv = &$levels[$k];
                $lv ??= $m + ['amount' => 0.0, 'count' => 0, 'lack_text' => ''];
                $lv['amount'] += $r['amount'];
                $lv['count']++;
                $lv['clients'] = $m['clients'];
                $lv['lack_clients'] = max($lv['lack_clients'], $m['lack_clients']);
                $lv['lack_pearl'] = max($lv['lack_pearl'], $m['lack_pearl']);
                unset($lv);

                continue;
            }
            $why = collect($r['details'])->first(static fn (string $t) => str_starts_with($t, 'Non spettante') || str_starts_with($t, 'Trattenuto')) ?? 'Non spettante.';
            $o = &$other[$why];
            $o ??= ['reason' => $why, 'amount' => 0.0, 'count' => 0];
            $o['amount'] += $r['amount'];
            $o['count']++;
            unset($o);
        }
        ksort($levels);
        foreach ($levels as &$lv) {
            $need = [];
            if ($lv['lack_clients'] > 0) {
                $need[] = $this->plural($lv['lack_clients'], 'cliente in più', 'clienti in più');
            }
            if ($lv['lack_pearl'] > 0) {
                $need[] = $this->plural($lv['lack_pearl'], 'Pearl in più', 'Pearl in più');
            }
            $lv['lack_text'] = implode(' e ', $need);
        }
        unset($lv);

        return ['total' => array_sum(array_column($rows, 'amount')), 'levels' => array_values($levels), 'other' => array_values($other), 'lines' => $rows];
    }

    /** @return list<array<string, mixed>> */
    private function structureRows(Consultant $consultant, Period $period): array
    {
        $rows = DB::table('structure_bonuses as b')->leftJoin('consultants as s', 's.id', '=', 'b.source_consultant_id')
            ->where('b.beneficiary_id', $consultant->id)->where('b.period_id', $period->id)->where('b.status', '!=', 'cancelled')
            ->get(['b.id', 'b.amount', 'b.engine_status', 'b.status', 'b.explanation', 's.name as source_name', 's.code as source_code']);
        $out = [];
        foreach ($rows as $b) {
            $ex = is_string($b->explanation) ? (json_decode($b->explanation, true) ?: []) : [];
            $src = $b->source_name ? "{$b->source_name} [{$b->source_code}]" : 'Un incaricato della rete';
            $details = [];
            if (isset($ex['formula'])) {
                $details[] = 'Differenza tra il bonus della qualifica e quello più alto già riconosciuto lungo la catena: '.$ex['formula'].' €.';
            }
            if (isset($ex['window_ends'])) {
                $details[] = 'Finestra di 30 giorni dall\'attivazione chiusa il '.Carbon::parse($ex['window_ends'])->format('d/m/Y').'.';
            }
            $out[] = [
                'id' => (int) $b->id, 'type' => 'structure', 'date' => isset($ex['reached_at']) ? Carbon::parse($ex['reached_at'])->format('d/m/Y') : null,
                'title' => 'Bonus di Struttura',
                'sentence' => "{$src} ha raggiunto il numero di clienti richiesto (PearlQ)".(isset($ex['reached_at']) ? ' il '.Carbon::parse($ex['reached_at'])->format('d/m/Y') : '').' entro la finestra. Spettano '.$this->eur((float) $b->amount).' a questa posizione della catena.',
                'details' => $details, 'order_number' => null, 'order_url' => null, 'movement_id' => null,
                'amount' => (float) $b->amount, 'base' => null, 'pct' => null, 'level' => null,
                'status' => (string) $b->engine_status, 'status_label' => match ($b->engine_status) { 'accrued' => 'Maturato', 'held' => 'Trattenuto', default => 'Non spettante' }, 'warning' => false, 'missing' => null,
            ];
        }

        return $out;
    }

    /**
     * Distanza reale tra chi ha generato l'ordine e chi incassa, con la catena. Se è diversa dal livello pagato,
     * dice che i livelli sono stati compressi.
     *
     * @param  list<string>  $details
     * @param  array<int|string, mixed>  $sponsors
     * @param  array<int|string, mixed>  $names
     */
    private function pathDetails(array &$details, int $sourceId, int $beneficiaryId, int $level, array $sponsors, array $names): void
    {
        if ($sourceId === 0) {
            return;
        }
        $chain = [$sourceId];
        $cur = $sourceId;
        for ($i = 0; $i < 40; $i++) {
            $cur = (int) ($sponsors[$cur] ?? 0);
            if ($cur === 0) {
                return;
            }
            $chain[] = $cur;
            if ($cur === $beneficiaryId) {
                break;
            }
        }
        if ($cur !== $beneficiaryId) {
            return;
        }
        $depth = count($chain) - 1;
        $details[] = 'Catena: '.implode(' → ', array_map(fn (int $id) => (string) ($names[$id] ?? "#{$id}"), $chain)).'.';
        if ($depth !== $level) {
            $details[] = "È a {$depth} livelli sotto, ma viene pagato come livello {$level}: chi sta in mezzo non rispetta i requisiti del suo livello e viene saltato (compressione).";
        }
    }

    private function nextTier(?\App\Domain\Engine\Plan\KosmosConfig $k, int $clients): string
    {
        if ($k === null) {
            return '';
        }
        foreach ($k->directTiers as $t) {
            if ($t['min_clients'] > $clients) {
                return ' Per il prossimo scaglione ('.$this->pct((float) $t['rate']->toFloat()).') ne servono '.$t['min_clients'].'.';
            }
        }

        return ' È lo scaglione più alto.';
    }

    /** @param  array<string, mixed>  $ex */
    private function why(string $status, string $reason, array $ex): string
    {
        $prefix = $status === 'held' ? 'Trattenuto' : 'Non spettante';
        $txt = match (true) {
            $reason === 'cessato' => 'incarico cessato',
            $reason === 'inattivo' => 'incaricato non attivo nel mese',
            $reason === 'onboarding_incompleto' => 'onboarding non completato (KYC, documenti)',
            $reason === 'provvedimento_trattenuta' => 'provvedimento di trattenuta in corso',
            str_starts_with($reason, 'qualifica_inferiore_a_') => 'qualifica inferiore a quella richiesta ('.substr($reason, 22).')',
            str_starts_with($reason, 'requisiti_livello_') => (preg_match('/^requisiti_livello_(\d+):?\s*(.*)$/', $reason, $mm) === 1)
                ? "requisiti del livello {$mm[1]} non raggiunti".($mm[2] !== '' ? " ({$mm[2]})" : '')
                : 'requisiti del livello non raggiunti',
            $reason === '' => 'motivo non indicato',
            default => $reason,
        };

        return "{$prefix}: {$txt}.";
    }

    /** @return list<string> */
    private function rules(?\App\Domain\Engine\Plan\KosmosConfig $k): array
    {
        if ($k === null) {
            return [];
        }
        $out = ['Base di calcolo: '.$this->pct((float) $k->vapShare->toFloat()).' dell\'importo IVA inclusa (un ordine da 150 € ha base 100 €).'];
        $t = array_map(fn (array $x) => $this->plural((int) $x['min_clients'], 'cliente', 'clienti').' → '.$this->pct((float) $x['rate']->toFloat()), $k->directTiers);
        $out[] = 'Diretti (sui clienti personali, su tutte le vendite del mese): '.implode(' · ', $t).'.';
        $lv = array_map(fn (array $x) => "L{$x['level']} ".$this->pct((float) $x['rate']->toFloat())." (almeno ".$this->plural((int) $x['min_clients'], 'cliente', 'clienti').", {$x['min_pearl']} Pearl)", $k->indirectLevels);
        $out[] = 'Indiretti: '.implode(' · ', $lv).'.';
        $out[] = 'Un cliente è chi fa un ordine'.($k->clientRule === 'min_amount' ? ' di almeno '.$this->eur((float) $k->clientMinAmount->toFloat()).' IVA inclusa' : '').'.';

        return $out;
    }

    private function kosmos(Period $period): ?\App\Domain\Engine\Plan\KosmosConfig
    {
        try {
            return $period->planVersion?->plan()->kosmos;
        } catch (Throwable) {
            return null;
        }
    }

    private function plural(int $n, string $one, string $many): string
    {
        return $n.' '.($n === 1 ? $one : $many);
    }

    private function eur(float $v): string
    {
        return number_format($v, 2, ',', '.').' €';
    }

    /** Frazione (0.2) → «20%». */
    private function pct(float $fraction): string
    {
        $s = number_format($fraction * 100, 2, ',', '');

        return rtrim(rtrim($s, '0'), ',').'%';
    }
}
