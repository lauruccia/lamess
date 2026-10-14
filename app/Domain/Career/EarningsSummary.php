<?php

declare(strict_types=1);

namespace App\Domain\Career;

use App\Domain\Payouts\StatementService;
use App\Domain\Plan\LegacyPlan;
use App\Models\CalculationRun;
use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * «Quanto sto maturando»: compensi dell'ultimo calcolo per tipo (maturati, trattenuti) e Bonus di Struttura per stato.
 * Sono stime finché il mese non è chiuso; lo statino ufficiale resta quello della pagina «Statini».
 */
final class EarningsSummary
{
    /**
     * @return array{period: ?string, closed: bool, types: list<array{type:string,label:string,accrued:float,held:float,lines:int}>, accrued: float, held: float, structure: array{calculated:float,confirmed:float,paid:float,count:int}}
     */
    public function for(int $consultantId): array
    {
        $period = Period::query()->orderByDesc('code')->first();
        $types = [];
        $accrued = $held = 0.0;
        if ($period !== null) {
            $runId = $period->official_run_id ?? CalculationRun::query()->where('period_id', $period->id)->where('status', 'completed')->orderByDesc('id')->value('id');
            if ($runId !== null) {
                $rows = DB::table('commission_lines')->where('run_id', $runId)->where('beneficiary_id', $consultantId)->where('bonus_type', '!=', 'structure')
                    ->groupBy('bonus_type')->selectRaw("bonus_type, sum(case when engine_status = 'accrued' then amount else 0 end) as accrued, sum(case when engine_status = 'held' then amount else 0 end) as held, count(*) as n")->get();
                foreach ($rows as $r) {
                    $types[] = [
                        'type' => (string) $r->bonus_type, 'label' => LegacyPlan::bonusLabel((string) $r->bonus_type, StatementService::LABELS[$r->bonus_type] ?? (string) $r->bonus_type),
                        'accrued' => (float) $r->accrued, 'held' => (float) $r->held, 'lines' => (int) $r->n,
                    ];
                    $accrued += (float) $r->accrued;
                    $held += (float) $r->held;
                }
            }
        }
        $structure = ['calculated' => 0.0, 'confirmed' => 0.0, 'paid' => 0.0, 'count' => 0];
        foreach (! Schema::hasTable('structure_bonuses') ? [] : DB::table('structure_bonuses')->where('beneficiary_id', $consultantId)->where('status', '!=', 'cancelled')->selectRaw('status, sum(amount) as amount, count(*) as n')->groupBy('status')->get() as $r) {
            if (isset($structure[(string) $r->status])) {
                $structure[(string) $r->status] = (float) $r->amount;
            }
            $structure['count'] += (int) $r->n;
        }

        return ['period' => $period?->code, 'closed' => $period?->status === Period::CLOSED, 'types' => $types, 'accrued' => $accrued, 'held' => $held, 'structure' => $structure];
    }
}
