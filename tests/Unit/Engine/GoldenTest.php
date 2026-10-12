<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Engine\Calc\ImpactReport;
use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;
use Tests\Support\Scenario;

/** Scenari golden G01–G26 non già coperti da BonusesTest/CareerTest (FSB G15–G17 sospeso; G21/G22/G24 appartengono a F2/F4/F5). */
final class GoldenTest extends EngineTestCase
{
    public function test_g01_box_123_net_is_100_pv(): void
    {
        self::assertDec('100.00', Num::suggestedPv('123.00', '1.23'));
        self::assertDec('0.81', Num::suggestedPv('1', '1.23'));
    }

    public function test_g02_direct_range(): void
    {
        $s = (new Scenario)->consultant('C')->consultant('B', null, 'BLACK_DIAMOND');
        $s->sale('C', 100);
        $s->sale('B', 100);
        $this->assertSame(['B' => '38.00', 'C' => '15.00'], self::amounts($s->run(), 'direct'));
    }

    public function test_g03_box_5_plus_1_is_500_pv_gift_has_zero_pv(): void
    {
        // 5 box a pagamento (500 PV) + 1 omaggio a 0 PV: l'omaggio non genera movimento (0 PV) e non altera i totali
        $s = (new Scenario)->consultant('C')->consultant('B', null, 'BLACK_DIAMOND');
        $s->sale('C', 500);
        $s->sale('B', 500);
        $r = $s->run();
        $this->assertSame(['B' => '190.00', 'C' => '75.00'], self::amounts($r, 'direct'));
        self::assertDec(500, $r->stats['C']->personalPvf);
    }

    public function test_g09_crystal_1600_counted_is_not_enough(): void
    {
        $s = (new Scenario)->consultant('X')->consultant('L1', 'X')->consultant('L2', 'X', 'PEARL');
        $s->opening = ['L1' => Num::of('2000'), 'L2' => Num::of('400')];
        $r = $s->run();
        self::assertDec('1600', $r->stats['X']->nextRank['pr_counted']);
        $this->assertNotSame('CRYSTAL', $r->stats['X']->achievedRank);
    }

    public function test_g13_skip_pearl_to_sapphire_pays_both_rank_bonuses(): void
    {
        $s = (new Scenario)
            ->consultant('X', null, 'PEARL')
            ->consultant('A', 'X', 'CRYSTAL')->consultant('B', 'X', 'PEARL')->consultant('C', 'X', 'PEARL');
        $s->opening = ['A' => Num::of('3000'), 'B' => Num::of('3000'), 'C' => Num::of('3000')];
        $r = $s->run();
        $this->assertSame('SAPPHIRE', $r->stats['X']->achievedRank);
        $paid = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === 'rank' && $l->beneficiaryId === 'X') {
                $paid[$l->explanation['rank']] = (string) $l->amount->toScale(2);
            }
        }
        $this->assertSame(['CRYSTAL' => '200.00', 'SAPPHIRE' => '300.00'], $paid);
    }

    public function test_g13b_highest_only_policy_pays_only_sapphire(): void
    {
        $s = (new Scenario(policies: ['rank_bonus_skipped_ranks' => 'highest_only']))
            ->consultant('X', null, 'PEARL')
            ->consultant('A', 'X', 'CRYSTAL')->consultant('B', 'X', 'PEARL')->consultant('C', 'X', 'PEARL');
        $s->opening = ['A' => Num::of('3000'), 'B' => Num::of('3000'), 'C' => Num::of('3000')];
        $this->assertEqualsCanonicalizing(['300.00'], array_values(array_map(
            fn ($v) => (string) BigDecimal::of($v)->toScale(2),
            self::amounts($s->run(), 'rank'),
        )));
    }

    public function test_g14_purple_diamond_65000_in_12_installments(): void
    {
        $parts = Num::splitInstallments('65000', 12);
        $this->assertCount(12, $parts);
        self::assertDec('5416.67', $parts[0]);
        self::assertDec('5416.63', $parts[11]);
        self::assertDec('65000.00', Num::sum($parts));

        $s = (new Scenario)
            ->consultant('X', null, 'ROSE_DIAMOND')
            ->consultant('A', 'X', 'ROSE_DIAMOND')->consultant('B', 'X', 'BLUE_DIAMOND')->consultant('C', 'X', 'BLUE_DIAMOND');
        $s->opening = ['A' => Num::of('600000'), 'B' => Num::of('300000'), 'C' => Num::of('300000')];
        $r = $s->run();
        $this->assertSame('PURPLE_DIAMOND', $r->stats['X']->achievedRank);
        $award = array_values(array_filter($r->rankAwards, fn ($a) => $a->rankCode === 'PURPLE_DIAMOND'))[0];
        $this->assertSame('awarded', $award->status);
        $this->assertCount(12, $award->installments);
        $this->assertSame('2026-10', $award->firstInstallmentPeriod);
        $inst = array_values(array_filter($r->lines, fn (Line $l) => $l->bonus === 'rank_installment'));
        $this->assertCount(1, $inst);
        self::assertDec('5416.67', $inst[0]->amount);
    }

    public function test_g19_refund_after_close_also_claws_back_matching(): void
    {
        $s = (new Scenario)->consultant('A', null, 'RUBY')->consultant('B', 'A')->consultant('C', 'B');
        $s->sale('C', 100, mid: 'm-ok');
        $r = $s->run();
        // il matching è calcolato su Direct+Team di C: A (Ruby, L2) → 5% di 15,00
        $this->assertContains('matching:C:A', array_map(fn (Line $l) => $l->key, $r->lines));
        $matching = array_values(array_filter($r->lines, fn (Line $l) => $l->key === 'matching:C:A'))[0];
        self::assertDec('0.75', $matching->amount);
    }

    public function test_g23_same_input_same_hash_different_input_different_hash(): void
    {
        $a = (new Scenario)->consultant('A', null, 'RUBY')->consultant('B', 'A');
        $a->sale('B', 700);
        $b = (new Scenario)->consultant('A', null, 'RUBY')->consultant('B', 'A');
        $b->sale('B', 701);
        $this->assertSame($a->run()->inputHash, $a->run()->inputHash);
        $this->assertNotSame($a->run()->inputHash, $b->run()->inputHash);
    }

    public function test_g26_simulation_pearl_direct_19_percent_reports_differences_without_touching_base(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/plan-prelaunch-2026-07-07.json'), true);
        $data['ranks'][1]['direct_pct'] = '19';
        $data['policies']['activity_month_rule'] = 'current_or_previous';
        $alt = PlanConfig::fromArray($data);

        $build = function (?PlanConfig $plan): Scenario {
            $s = new Scenario(plan: $plan);
            $s->consultant('A', null, 'DIAMOND')->consultant('P', 'A', 'PEARL');
            $s->sale('P', 1000);

            return $s;
        };
        $base = $build(null)->run();
        $sim = $build($alt)->run();
        $report = ImpactReport::compare($base, $sim);

        // base: P Direct 18% = 180; A Team 12% = 120 → sim: P 19% = 190; A Team 11% = 110
        self::assertDec('10.00', $report['totals']['direct']['diff']);
        self::assertDec('-10.00', $report['totals']['team']['diff']);
        self::assertDec('10.00', $report['by_partner']['P']['diff']);
        // A: Team −10 e Matching +1 (10% sul Direct di P salito da 180 a 190)
        self::assertDec('-9.00', $report['by_partner']['A']['diff']);
        self::assertDec('180.00', $base->earned('P', 'direct'));
        self::assertDec('0.18', PlanLoader::prelaunch()->rank('PEARL')->directRate);
    }

    public function test_payout_ratio(): void
    {
        $s = (new Scenario)->consultant('A', null, 'DIAMOND')->consultant('B', 'A', 'CRYSTAL');
        $s->sale('B', 100); // 100 PV = 123 € di imponibile
        $r = $s->run();
        // Direct 21 + Team 9 + Matching 2,10 = 32,10 su 123 €
        self::assertDec('0.260976', ImpactReport::payoutRatio($r, '123'));
    }
}
