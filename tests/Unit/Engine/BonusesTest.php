<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Engine\Calc\BonusContext;
use App\Domain\Engine\Calc\CareerState;
use App\Domain\Engine\Calc\MatchingCalculator;
use App\Domain\Engine\Calc\Tree;
use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\OriginalLine;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Tests\Support\Scenario;

/** Direct, Team, Matching: esempi documentati nel PMK e casi limite. */
final class BonusesTest extends EngineTestCase
{
    public function test_pearl_500_pv_direct_90_euro(): void
    {
        $s = (new Scenario)->consultant('SP')->consultant('P', 'SP', 'PEARL');
        $s->sale('P', 500);
        $r = $s->run();
        $direct = array_values(array_filter($r->lines, fn (Line $l) => $l->bonus === 'direct'));
        $this->assertCount(1, $direct);
        $this->assertSame('P', $direct[0]->beneficiaryId);
        self::assertDec('90.00', $direct[0]->amount);
        self::assertDec('0.18', $direct[0]->rate);
    }

    public function test_box_direct_range_15_to_38(): void
    {
        $s = (new Scenario)->consultant('C')->consultant('B', null, 'BLACK_DIAMOND');
        $s->sale('C', 100);
        $s->sale('B', 100);
        $this->assertSame(['B' => '38.00', 'C' => '15.00'], self::amounts($s->run(), 'direct'));
    }

    public function test_diamond_over_crystal_8000_pv_team_720(): void
    {
        $s = (new Scenario)->consultant('A', null, 'DIAMOND')->consultant('B', 'A', 'CRYSTAL');
        $s->sale('B', 8000);
        $r = $s->run();
        $this->assertSame(['B' => '1680.00'], self::amounts($r, 'direct'));
        $team = array_values(array_filter($r->lines, fn (Line $l) => $l->bonus === 'team'));
        $this->assertCount(1, $team);
        $this->assertSame('A', $team[0]->beneficiaryId);
        self::assertDec('720.00', $team[0]->amount);
        self::assertDec('0.09', $team[0]->rate);
    }

    public function test_team_not_on_personal_sales(): void
    {
        $s = (new Scenario)->consultant('A', null, 'DIAMOND');
        $s->sale('A', 1000);
        $r = $s->run();
        $this->assertSame([], self::amounts($r, 'team'));
        $this->assertSame(['A' => '300.00'], self::amounts($r, 'direct'));
    }

    public function test_team_differential_absorbs_intermediates_and_breakaway(): void
    {
        $s = (new Scenario)
            ->consultant('A', null, 'DIAMOND')
            ->consultant('B', 'A', 'PEARL')
            ->consultant('C', 'B')
            ->consultant('D', 'A', 'DIAMOND')
            ->consultant('E', 'D');
        $s->sale('C', 1000);
        $s->sale('E', 1000);
        $r = $s->run();
        $team = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === 'team') {
                $team[] = "{$l->sourceMovementId}:{$l->beneficiaryId}:{$l->amount}";
            }
        }
        sort($team);
        $this->assertSame(['m1:A:120.00', 'm1:B:30.00', 'm2:D:150.00'], $team);
    }

    public function test_pearl_over_crystal_gets_nothing(): void
    {
        $s = (new Scenario)->consultant('A', null, 'PEARL')->consultant('B', 'A', 'CRYSTAL');
        $s->sale('B', 1000);
        $this->assertSame([], self::amounts($s->run(), 'team'));
    }

    public function test_inactive_intermediate_compression_policy(): void
    {
        $build = function (string $policy) {
            $s = (new Scenario(policies: ['team_ineligible_upline' => $policy]))
                ->consultant('A', null, 'DIAMOND')
                ->consultant('B', 'A', 'EMERALD', active: false)
                ->consultant('C', 'B');
            $s->sale('C', 100);

            return $s->run();
        };
        $compress = $build('compress');
        $this->assertSame(['A' => '15.00'], self::amounts($compress, 'team'));
        $excluded = array_values(array_filter($compress->lines, fn (Line $l) => $l->bonus === 'team' && $l->status === 'excluded'));
        $this->assertSame('B', $excluded[0]->beneficiaryId);
        $this->assertSame('inattivo', $excluded[0]->reason);
        $this->assertSame(['A' => '2.00'], self::amounts($build('absorb'), 'team'));
    }

    public function test_personal_purchase_buyer_earns_nothing_sponsor_gets_differential(): void
    {
        // PMK 070726 + Laura 02/10: chi compra non guadagna sul proprio acquisto; lo sponsor prende la differenza (Team)
        $s = (new Scenario)->consultant('S', null, 'PEARL')->consultant('C', 'S');
        $s->sale('C', 100, kind: 'pvc');
        $r = $s->run();
        $this->assertSame([], self::amounts($r, 'direct'));
        $this->assertSame(['S' => '3.00'], self::amounts($r, 'team'));
        self::assertDec(100, $r->stats['C']->personalPvc);
        self::assertDec(0, $r->stats['S']->personalPvc);
    }

    public function test_personal_purchase_legacy_policy_sponsor_gets_direct(): void
    {
        $s = (new Scenario(policies: ['personal_purchase_direct_beneficiary' => 'sponsor']))->consultant('S', null, 'PEARL')->consultant('C', 'S');
        $s->sale('C', 100, kind: 'pvc');
        $this->assertSame(['S' => '18.00'], self::amounts($s->run(), 'direct'));
    }

    public function test_personal_purchase_policy_self(): void
    {
        $s = (new Scenario(policies: ['personal_purchase_direct_beneficiary' => 'self']))->consultant('S')->consultant('C', 'S');
        $s->sale('C', 100, kind: 'pvc');
        $this->assertSame(['C' => '15.00'], self::amounts($s->run(), 'direct'));
    }

    public function test_ruby_matching_pmk_example_100_euro(): void
    {
        $s = (new Scenario)
            ->consultant('A', null, 'RUBY')->consultant('B', 'A')
            ->consultant('C1', 'B')->consultant('C2', 'B')->consultant('C3', 'B');
        $tree = new Tree(array_values($s->nodes));
        $prior = $s->prior();
        $career = new CareerState($s->plan, $tree, $prior);
        $period = PeriodKey::parse('2026-10');
        $active = array_fill_keys(array_keys($tree->nodes), true);
        $eff = [];
        foreach ($tree->nodes as $id => $n) {
            $eff[(string) $id] = $career->effectiveSeq($n, $period);
        }
        $ctx = new BonusContext($s->plan, $period, $tree, $career, $prior, $active, $eff);
        $lines = [];
        foreach ([['B', '1000'], ['C1', '500'], ['C2', '400'], ['C3', '100']] as [$b, $a]) {
            $lines[] = new Line("direct:{$b}", 'direct', $b, Num::of($a), Num::of($a));
        }
        $got = MatchingCalculator::calculate($ctx, $lines);
        $toA = array_values(array_filter($got, fn (Line $l) => $l->beneficiaryId === 'A' && $l->status === 'accrued'));
        self::assertDec('100.00', Num::sum(array_map(fn (Line $l) => $l->amount, $toA)));
        $set = [];
        foreach ($toA as $l) {
            $set[] = "{$l->sourcePartnerId}:{$l->level}:{$l->amount}";
        }
        sort($set);
        $this->assertSame(['B:1:50.00', 'C1:2:25.00', 'C2:2:20.00', 'C3:2:5.00'], $set);
    }

    public function test_matching_end_to_end_excludes_matching_rank_fsb_from_base(): void
    {
        $s = (new Scenario)->consultant('A', null, 'RUBY')->consultant('B', 'A')->consultant('C', 'B');
        $s->sale('B', 6000);
        $s->sale('C', 8000);
        $r = $s->run();
        $m = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === 'matching' && $l->beneficiaryId === 'A') {
                $m["{$l->sourcePartnerId}:{$l->level}"] = (string) $l->amount;
            }
        }
        $this->assertSame(['B:1' => '45.00', 'C:2' => '60.00'], $m);
    }

    public function test_matching_requires_sapphire_and_active(): void
    {
        $s = (new Scenario)->consultant('A', null, 'CRYSTAL')->consultant('B', 'A');
        $s->sale('B', 1000);
        $this->assertSame([], self::amounts($s->run(), 'matching'));

        $s2 = (new Scenario)->consultant('A', null, 'SAPPHIRE', active: false)->consultant('B', 'A');
        $s2->sale('B', 1000);
        $r2 = $s2->run();
        $this->assertSame([], self::amounts($r2, 'matching'));
        $this->assertNotEmpty(array_filter($r2->lines, fn (Line $l) => $l->bonus === 'matching' && $l->status === 'excluded'));
    }

    public function test_indirect_requires_pearl_active_onboarding(): void
    {
        $s = (new Scenario)->consultant('A', null, 'PEARL', onboardingComplete: false)->consultant('B', 'A');
        $s->sale('B', 1000);
        $r = $s->run();
        $this->assertSame([], self::amounts($r, 'team'));
        $reasons = array_map(fn (Line $l) => $l->reason, array_values(array_filter($r->lines, fn (Line $l) => $l->bonus === 'team')));
        $this->assertSame(['onboarding_incompleto'], $reasons);
        $s->sale('A', 100);
        $this->assertSame('18.00', self::amounts($s->run(), 'direct')['A']);
    }

    public function test_kyc_policy_accrued_held(): void
    {
        $s = new Scenario(policies: ['onboarding_incomplete_indirect' => 'accrued_held']);
        $s->consultant('A', null, 'PEARL', onboardingComplete: false)->consultant('B', 'A');
        $s->sale('B', 1000);
        $team = array_values(array_filter($s->run()->lines, fn (Line $l) => $l->bonus === 'team'));
        $this->assertSame('held', $team[0]->status);
        self::assertDec('30.00', $team[0]->amount);
    }

    public function test_refund_same_period_nets_out(): void
    {
        $s = (new Scenario)->consultant('A', null, 'DIAMOND')->consultant('B', 'A');
        $s->sale('B', 500, mid: 'sale');
        $s->sale('B', -200, on: '2026-10-20', reverses: 'sale', mid: 'ref');
        $r = $s->run();
        self::assertDec('45.00', $r->earned('B', 'direct'));
        self::assertDec('45.00', $r->earned('A', 'team'));
        self::assertDec(300, $r->stats['B']->personalPrClosing);
    }

    public function test_refund_after_close_clawback_proportional(): void
    {
        $s = (new Scenario(period: '2026-11'))->consultant('A', null, 'DIAMOND')->consultant('B', 'A');
        $orig = new Movement('sale', 'B', 'pvf', Num::of('500'), Num::of('500'), Scenario::dt('2026-10-05'));
        $s->sale('B', -250, on: '2026-11-03', reverses: 'sale', mid: 'ref');
        $r = $s->run([
            'originalMovements' => ['sale' => $orig],
            'originalLines' => ['sale' => [
                new OriginalLine('direct:sale', 'direct', 'B', Num::of('75.00'), Num::of('0.15'), '2026-10'),
                new OriginalLine('team:sale:A', 'team', 'A', Num::of('75.00'), Num::of('0.15'), '2026-10'),
            ]],
            'personalPrOpening' => ['B' => Num::of('500')],
        ]);
        $claw = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === 'clawback') {
                $claw[$l->beneficiaryId] = (string) $l->amount;
            }
        }
        ksort($claw);
        $this->assertSame(['A' => '-37.50', 'B' => '-37.50'], $claw);
        $this->assertEmpty(array_filter($r->lines, fn (Line $l) => in_array($l->bonus, ['direct', 'team'], true)));
        self::assertDec(250, $r->stats['B']->personalPrClosing);
        self::assertDec(0, $r->stats['B']->activityPoints);
    }

    public function test_recalculation_is_repeatable(): void
    {
        $s = (new Scenario)->consultant('A', null, 'RUBY')->consultant('B', 'A')->consultant('C', 'B');
        $s->sale('B', 700);
        $s->sale('C', 300, on: '2026-10-11');
        $r1 = $s->run();
        $r2 = $s->run();
        $this->assertSame($r1->inputHash, $r2->inputHash);
        $f = fn ($r) => array_map(fn (Line $l) => [$l->key, (string) $l->amount, $l->status], $r->lines);
        $this->assertSame($f($r1), $f($r2));
    }
}
