<?php

declare(strict_types=1);

namespace App\Domain\Payouts;

use App\Domain\Plan\LegacyPlan;
use App\Domain\Engine\Support\Num;
use App\Models\Consultant;
use App\Models\Period;
use App\Models\Settlement;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Statino mensile dell’incaricato: ogni importo con il suo perché. Esiste solo per i periodi chiusi. */
final class StatementService
{
    public const LABELS = [
        'direct' => 'Bonus diretto', 'team' => 'Team Bonus', 'matching' => 'Matching Bonus', 'rank' => 'Rank Bonus',
        'rank_installment' => 'Rata Rank Bonus', 'fsb' => 'Fast Start Bonus', 'clawback' => 'Storni (resi)',
        'indirect' => 'Compensi indiretti', 'indirect_ext' => 'Indiretti estesi', 'structure' => 'Bonus di Struttura',
    ];

    /** @return array<string,mixed> */
    public function build(Period $period, Consultant $c): array
    {
        if (! $period->isClosed() || $period->official_run_id === null) {
            throw new PayoutException("Lo statino di {$period->code} è disponibile dopo la chiusura del mese.");
        }
        $rows = DB::table('commission_lines as l')
            ->leftJoin('consultants as s', 's.id', '=', 'l.source_consultant_id')
            ->where('l.run_id', $period->official_run_id)->where('l.beneficiary_id', $c->id)->where('l.status', 'locked')
            ->whereIn('l.engine_status', ['accrued', 'held'])
            ->orderBy('l.bonus_type')->orderBy('l.id')
            ->get(['l.bonus_type', 'l.base_amount', 'l.pct', 'l.amount', 'l.level', 'l.engine_status', 'l.reason', 'l.explanation', 's.code as source_code', 's.name as source_name']);

        $groups = [];
        $accrued = BigDecimal::zero();
        $held = BigDecimal::zero();
        foreach ($rows as $r) {
            $expl = json_decode((string) $r->explanation, true) ?: [];
            $hide = LegacyPlan::hidden() && LegacyPlan::isLegacyBonus((string) $r->bonus_type);   // vecchio piano nascosto: una sola voce neutra
            $g = &$groups[$hide ? 'legacy' : $r->bonus_type];
            $g ??= ['label' => $hide ? LegacyPlan::NEUTRAL_LABEL : (self::LABELS[$r->bonus_type] ?? $r->bonus_type), 'lines' => [], 'accrued' => BigDecimal::zero(), 'held' => BigDecimal::zero()];
            $g['lines'][] = [
                'source' => $r->source_code ? "{$r->source_name} [{$r->source_code}]" : '—',
                'level' => $hide ? null : $r->level, 'base' => (string) $r->base_amount, 'pct' => $r->pct === null ? null : Num::pctLabel(BigDecimal::of((string) $r->pct)),
                'amount' => (string) $r->amount, 'held' => $r->engine_status === 'held', 'why' => $hide ? '' : ($expl['formula'] ?? $r->reason),
                'extended' => ! $hide && $r->bonus_type === 'indirect_ext',
            ];
            if ($r->engine_status === 'held') {
                $g['held'] = $g['held']->plus((string) $r->amount);
                $held = $held->plus((string) $r->amount);
            } else {
                $g['accrued'] = $g['accrued']->plus((string) $r->amount);
                $accrued = $accrued->plus((string) $r->amount);
            }
            unset($g);
        }

        $settlement = Settlement::with('payout')->where('period_id', $period->id)->where('consultant_id', $c->id)->first();
        $carried = BigDecimal::zero();
        foreach (Settlement::where('consultant_id', $c->id)->where('status', 'open')->whereHas('period', fn ($p) => $p->where('code', '<', $period->code))->pluck('accrued') as $a) {
            $carried = $carried->plus($a);
        }

        foreach ($groups as &$g) {
            $g['accrued'] = (string) Num::money($g['accrued']);
            $g['held'] = (string) Num::money($g['held']);
        }
        unset($g);

        return [
            'period' => $period, 'consultant' => $c, 'groups' => $groups, 'sections' => self::sections($groups), 'total' => (string) Num::money($accrued),
            'accrued' => (string) Num::money($accrued), 'held' => (string) Num::money($held),
            'carried' => (string) Num::money($carried), 'settlement' => $settlement, 'payout' => $settlement?->payout,
            'min_payout' => (string) $period->planVersion->plan()->minPayout, 'seller' => config('lamess.seller'),
        ];
    }

    /**
     * Voci dello statino mensile nell'ordine: Dirette, Indirette (con gli estesi dentro), poi eventuali altre voci.
     * Il Bonus di Struttura non è nel mese: ha il suo statino settimanale.
     *
     * @param  array<string,array<string,mixed>>  $groups
     * @return list<array{key:string,label:string,lines:list<array<string,mixed>>,accrued:string,held:string}>
     */
    public static function sections(array $groups): array
    {
        $out = [];
        foreach ($groups as $key => $g) {
            $sk = $key === 'indirect_ext' ? 'indirect' : (string) $key;
            $label = ['direct' => 'Dirette', 'indirect' => 'Indirette'][$sk] ?? $g['label'];
            $cur = $out[$sk] ?? ['key' => $sk, 'label' => $label, 'lines' => [], 'accrued' => '0.00', 'held' => '0.00'];
            $cur['lines'] = array_merge($cur['lines'], $g['lines']);
            $cur['accrued'] = (string) Num::money(BigDecimal::of($cur['accrued'])->plus($g['accrued']));
            $cur['held'] = (string) Num::money(BigDecimal::of($cur['held'])->plus($g['held']));
            $out[$sk] = $cur;
        }
        $rank = ['direct' => 0, 'indirect' => 1];
        uksort($out, static fn ($a, $b) => ($rank[$a] ?? 9) <=> ($rank[$b] ?? 9));

        return array_values($out);
    }

    public function pdf(Period $period, Consultant $c): string
    {
        return Pdf::loadView('pdf.statement', $this->build($period, $c))->output();
    }
}
