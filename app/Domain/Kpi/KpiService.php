<?php

declare(strict_types=1);

namespace App\Domain\Kpi;

use App\Models\AnnualFee;
use App\Models\CalculationRun;
use App\Models\Consultant;
use App\Models\Customer;
use App\Models\IvdContract;
use App\Models\KycDocument;
use App\Models\Period;
use App\Models\PvMovement;
use Illuminate\Support\Facades\DB;

/**
 * Indicatori della dashboard del back-office (RF-120). Sola lettura: nessun KPI scrive dati.
 * Per i dati di periodo usa l'ultima corsa completata (ufficiale se esiste, altrimenti live),
 * così la dashboard coincide con quanto vede chi lavora sul calcolo.
 */
final class KpiService
{
    public function latestPeriodCode(): ?string
    {
        return Period::query()->orderByDesc('code')->value('code');
    }

    /** @return array<string,string> code => code, dal più recente */
    public function periodOptions(): array
    {
        return Period::query()->orderByDesc('code')->pluck('code', 'code')->all();
    }

    public function run(Period $p): ?CalculationRun
    {
        $official = $p->official_run_id ? CalculationRun::find($p->official_run_id) : null;

        return $official ?? CalculationRun::query()->where('period_id', $p->id)->where('status', 'completed')
            ->orderByDesc('id')->first();
    }

    /**
     * Indicatori di rete (non dipendono dal periodo) e di periodo.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?string $periodCode): array
    {
        $period = $periodCode ? Period::where('code', $periodCode)->first() : null;

        $out = [
            'period' => $period?->code,
            'period_status' => $period?->status,
            'fan_total' => Customer::count(),
            'consultants_by_status' => Consultant::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)->all(),
            'kyc_pending' => KycDocument::where('status', 'pending')->count(),
            'ivd_to_review' => IvdContract::where('status', 'signed')->count(),
            'fees_due' => AnnualFee::where('status', 'due')->count(),
            'fees_due_amount' => (string) AnnualFee::where('status', 'due')->sum('amount'),
        ];

        if (! $period) {
            return $out + ['new_fan' => 0, 'new_consultants' => 0, 'movements' => 0, 'pv' => '0.00', 'cv' => '0.00',
                'pending_payment' => 0, 'active' => 0, 'ranks' => [], 'commissions' => [], 'commissions_total' => '0.00',
                'held_total' => '0.00', 'payout_ratio' => null, 'run_type' => null];
        }

        $mv = PvMovement::query()->where('period_id', $period->id)->where('status', 'confirmed');
        $out += [
            'new_fan' => Customer::whereBetween('created_at', [$period->starts_at, $period->ends_at])->count(),
            'new_consultants' => Consultant::whereBetween('created_at', [$period->starts_at, $period->ends_at])->count(),
            'movements' => (clone $mv)->count(),
            'pv' => number_format((float) (clone $mv)->sum('pv'), 2, '.', ''),
            'cv' => number_format((float) (clone $mv)->sum('cv'), 2, '.', ''),
            'pending_payment' => PvMovement::where('period_id', $period->id)->where('status', 'pending_payment')->count(),
        ];

        $run = $this->run($period);
        $out['run_type'] = $run?->type;
        $out['active'] = 0;
        $out['ranks'] = [];
        $out['commissions'] = [];
        $out['commissions_total'] = '0.00';
        $out['held_total'] = '0.00';
        $out['payout_ratio'] = null;
        if ($run) {
            $out['active'] = (int) DB::table('consultant_period_stats')->where('run_id', $run->id)->where('is_active', true)->count();
            $out['ranks'] = DB::table('consultant_period_stats')->where('run_id', $run->id)
                ->select('effective_rank', DB::raw('count(*) as n'))->groupBy('effective_rank')->pluck('n', 'effective_rank')
                ->map(fn ($n) => (int) $n)->all();
            $lines = DB::table('commission_lines')->where('run_id', $run->id);
            $out['commissions'] = (clone $lines)->where('engine_status', 'accrued')
                ->select('bonus_type', DB::raw('sum(amount) as s'))->groupBy('bonus_type')->pluck('s', 'bonus_type')
                ->map(fn ($s) => number_format((float) $s, 2, '.', ''))->all();
            $total = (float) (clone $lines)->where('engine_status', 'accrued')->sum('amount');
            $out['commissions_total'] = number_format($total, 2, '.', '');
            $out['held_total'] = number_format((float) (clone $lines)->where('engine_status', 'held')->sum('amount'), 2, '.', '');
            $cv = (float) $out['cv'];
            $out['payout_ratio'] = $cv > 0 ? round($total / $cv * 100, 2) : null;
        }

        return $out;
    }
}
