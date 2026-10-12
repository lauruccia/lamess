<?php

declare(strict_types=1);

namespace Tests\Feature\Periods;

use App\Domain\Ledger\PvLedger;
use App\Domain\Periods\PeriodService;
use App\Domain\Periods\PeriodStateException;
use App\Models\CalculationRun;
use App\Models\CommissionLine;
use App\Models\Period;
use App\Models\RankAchievement;
use App\Models\RankAward;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\NetworkDb;
use Tests\TestCase;

final class PeriodLifecycleTest extends TestCase
{
    use NetworkDb;
    use RefreshDatabase;

    private function svc(): PeriodService
    {
        return app(PeriodService::class);
    }

    private function amount(CalculationRun $run, int $beneficiary, string $bonus): string
    {
        return number_format((float) CommissionLine::where('run_id', $run->id)->where('beneficiary_id', $beneficiary)
            ->where('bonus_type', $bonus)->sum('amount'), 2, '.', '');
    }

    public function test_g04_team_bonus_is_computed_and_persisted_with_explanation(): void
    {
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $b = $this->consultant('B', $a, 'CRYSTAL');
        $this->sale($p, $b, '8000');

        $run = $this->svc()->calculate($p);

        $this->assertSame('completed', $run->status);
        $this->assertSame('720.00', $this->amount($run, $a->id, 'team'));
        $this->assertSame('1680.00', $this->amount($run, $b->id, 'direct'));
        $line = CommissionLine::where('run_id', $run->id)->where('bonus_type', 'team')->firstOrFail();
        $this->assertSame('draft', $line->status);
        $this->assertNotEmpty($line->explanation);
        $this->assertSame($b->id, $line->source_consultant_id);
        $this->assertSame('0.0900', $line->pct);
    }

    public function test_g23_recalculation_is_idempotent_and_replaces_previous_run(): void
    {
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $b = $this->consultant('B', $a, 'CRYSTAL');
        $this->sale($p, $b, '500');

        $r1 = $this->svc()->calculate($p);
        $n = CommissionLine::count();
        $r2 = $this->svc()->calculate($p);

        $this->assertSame($r1->input_hash, $r2->input_hash);
        $this->assertSame($n, CommissionLine::count());
        $this->assertSame(1, CalculationRun::where('type', 'live')->count());
        $this->assertSame(Period::OPEN, $p->refresh()->status);
    }

    public function test_g22_pending_payment_has_no_effect_and_blocks_close(): void
    {
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $this->sale($p, $a, '100');
        $this->sale($p, $a, '900', status: 'pending_payment');

        $run = $this->svc()->calculate($p, 'official');

        $this->assertSame('30.00', $this->amount($run, $a->id, 'direct'));
        $this->assertSame(Period::REVIEW, $p->refresh()->status);
        $this->expectException(PeriodStateException::class);
        $this->expectExceptionMessage('in attesa di pagamento');
        $this->svc()->close($p);
    }

    public function test_close_requires_official_run_and_fresh_data(): void
    {
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $this->sale($p, $a, '100');
        try {
            $this->svc()->close($p);
            $this->fail('atteso errore: nessun runtime');
        } catch (PeriodStateException $e) {
            $this->assertStringContainsString('runtime ufficiale', $e->getMessage());
        }
        $this->svc()->calculate($p, 'official');
        $this->sale($p, $a, '50', '2026-10-11 10:00'); // dati cambiati dopo il runtime
        $this->expectExceptionMessage('dati sono cambiati');
        $this->svc()->close($p);
    }

    public function test_g24_closed_period_is_immutable(): void
    {
        $p = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $this->sale($p, $a, '100');
        $this->svc()->calculate($p, 'official');
        $closed = $this->svc()->close($p);

        $this->assertSame(Period::CLOSED, $closed->status);
        $this->assertSame(1, CommissionLine::where('status', 'locked')->count());
        $runs = CalculationRun::count();
        $lines = CommissionLine::count();
        try {
            $this->svc()->calculate($p);
            $this->fail('atteso errore');
        } catch (PeriodStateException) {
        }
        $this->assertSame($runs, CalculationRun::count());
        $this->assertSame($lines, CommissionLine::count());
        $this->expectException(PeriodStateException::class);
        $this->sale($p, $a, '10', '2026-10-12 10:00');
    }

    public function test_g19_refund_on_closed_period_creates_clawback_in_open_period(): void
    {
        $oct = $this->openPeriod('2026-10');
        $a = $this->consultant('A', null, 'DIAMOND');
        $b = $this->consultant('B', $a, 'CRYSTAL');
        $sale = $this->sale($oct, $b, '100');
        $this->svc()->calculate($oct, 'official');
        $this->svc()->close($oct);

        $nov = $this->openPeriod('2026-11');
        PvLedger::record($nov, $b, 'pvf', '-100', $this->at('2026-11-05 10:00'), type: 'refund', reverses: $sale);
        $run = $this->svc()->calculate($nov);

        $this->assertSame('-21.00', $this->amount($run, $b->id, 'clawback'));
        $this->assertSame('-9.00', $this->amount($run, $a->id, 'clawback'));
        $this->assertSame('0.00', $this->amount($run, $b->id, 'direct'));
    }

    public function test_rank_is_achieved_closed_and_carried_to_next_period_with_pr_opening(): void
    {
        $plan = $this->plan(['rank_bonus_installment_threshold' => '100', 'rank_bonus_installments' => 3]);
        $oct = $this->openPeriod('2026-10', $plan);
        $x = $this->consultant('X');
        $pearl = $this->consultant('P', $x, 'PEARL');
        $this->sale($oct, $pearl, '600', '2026-10-05 10:00');
        $this->sale($oct, $x, '1800', '2026-10-06 10:00', 'pvc');

        $run = $this->svc()->calculate($oct, 'official');
        $this->assertSame(1, CommissionLine::where('run_id', $run->id)->where('beneficiary_id', $x->id)->where('bonus_type', 'rank_installment')->count());
        $this->svc()->close($oct);

        $ach = RankAchievement::where('consultant_id', $x->id)->where('rank_code', 'CRYSTAL')->firstOrFail();
        $this->assertSame('2026-11-01', $ach->effective_from->format('Y-m-d') ?? '');
        $award = RankAward::where('award_key', "{$x->id}:CRYSTAL")->firstOrFail();
        $this->assertSame('awarded', $award->status);
        $this->assertSame(['paid', 'due', 'due'], $award->installments()->orderBy('number')->pluck('status')->all());
        $this->assertSame(['66.67', '66.67', '66.66'], $award->installments()->orderBy('number')->pluck('amount')->all());

        $nov = $this->openPeriod('2026-11', $plan);
        $run2 = $this->svc()->calculate($nov);
        // rata 2/3 in scadenza; qualifica Crystal efficace dal 1/11 → Direct 21% su una nuova vendita
        $this->assertSame('66.67', $this->amount($run2, $x->id, 'rank_installment'));
        $stat = $run2->stats()->where('consultant_id', $x->id)->firstOrFail();
        $this->assertSame('1800.00', $stat->pr_opening);
        $this->assertSame('CRYSTAL', $stat->effective_rank);
    }

    public function test_fast_start_is_paid_once_and_survives_period_close(): void
    {
        $oct = $this->openPeriod('2026-10');
        $x = $this->consultant('X');
        $d1 = $this->consultant('D1', $x);
        $d2 = $this->consultant('D2', $x);
        $this->sale($oct, $d1, '100', '2026-10-03 10:00');
        $this->sale($oct, $d2, '100', '2026-10-03 11:00');

        $run = $this->svc()->calculate($oct, 'official');
        $this->assertSame('40.00', $this->amount($run, $x->id, 'fsb'));
        $this->svc()->close($oct);

        $nov = $this->openPeriod('2026-11');
        $this->sale($nov, $d1, '100', '2026-11-05 10:00');
        $run2 = $this->svc()->calculate($nov);
        $this->assertSame('0.00', $this->amount($run2, $x->id, 'fsb'), 'lo step 1 non si ripaga; gli step 2+ sono scaduti');
    }
}
