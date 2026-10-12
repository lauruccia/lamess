<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Engine\Calc\Activity;
use App\Domain\Engine\Calc\CareerState;
use App\Domain\Engine\Calc\Tree;
use App\Domain\Engine\Model\AchievedRank;
use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\Node;
use App\Domain\Engine\Model\PriorState;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;
use Tests\Support\Scenario;

/** Qualifiche, regola del 50%, efficacia, attività mensile. */
final class CareerTest extends EngineTestCase
{
    public function test_crystal_lines_1800_600_count_1800(): void
    {
        $s = (new Scenario)->consultant('X')->consultant('L1', 'X')->consultant('L2', 'X');
        $s->opening = ['L1' => Num::of('1800'), 'L2' => Num::of('600')];
        $r = $s->run();
        $detail = $r->stats['X']->nextRank;
        $this->assertSame('PEARL', $r->stats['X']->achievedRank); // 2.400 PR di gruppo; Pearl non ha la regola 50%
        $this->assertSame('CRYSTAL', $detail['rank']);
        self::assertDec('1800.00', $detail['pr_counted']);
        self::assertDec('1200', $detail['lines']['L1']['counted']);
        $this->assertFalse($detail['pr_ok']);
    }

    public function test_crystal_reached_with_balanced_lines_and_pearl_in_a_line(): void
    {
        $s = (new Scenario)->consultant('X')->consultant('L1', 'X')->consultant('L2', 'X');
        $s->opening = ['L1' => Num::of('1200')];
        $s->sale('L2', 1200, on: '2026-10-20 10:00');
        $r = $s->run();
        $got = array_map(fn ($a) => "{$a->partnerId}:{$a->rankCode}", $r->achievements);
        $this->assertSame(['L1:PEARL', 'L2:PEARL', 'X:PEARL', 'X:CRYSTAL'], $got);
        $crystal = array_values(array_filter($r->achievements, fn ($a) => $a->rankCode === 'CRYSTAL'))[0];
        $this->assertSame('2026-11-01', $crystal->effectiveFrom); // PMK p.19
        $this->assertSame('CONSULTANT', $r->stats['X']->effectiveRank);
    }

    public function test_structure_requirements_on_different_lines(): void
    {
        $plan = PlanLoader::prelaunch();
        $since = Scenario::dt('2024-01-01');
        $nodes = [new Node('X', null, $since), new Node('A', 'X', $since), new Node('B', 'X', $since), new Node('C', 'X', $since), new Node('A2', 'A', $since)];
        $ranks = function (array $assign) use ($plan): array {
            $out = [];
            foreach ($assign as $nid => $code) {
                foreach ($plan->ranks as $r) {
                    if ($r->sequence >= 1 && $r->sequence <= $plan->rank($code)->sequence) {
                        $out[] = new AchievedRank($nid, $r->code, Scenario::dt('2024-06-01'));
                    }
                }
            }

            return $out;
        };
        $tree = new Tree($nodes);
        $opening = ['X' => Num::of('0'), 'A' => Num::of('150000'), 'B' => Num::of('75000'), 'C' => Num::of('75000')];

        $ok = new CareerState($plan, $tree, new PriorState(personalPrOpening: $opening, achievedRanks: $ranks(['A' => 'DIAMOND', 'B' => 'EMERALD', 'C' => 'EMERALD'])));
        $ok->checkpoint(Scenario::dt('2026-10-01'));
        $this->assertSame('BLUE_DIAMOND', $plan->bySeq($ok->highest['X'])->code); // G10

        $same = new CareerState($plan, $tree, new PriorState(personalPrOpening: $opening, achievedRanks: $ranks(['A' => 'DIAMOND', 'A2' => 'EMERALD', 'B' => 'EMERALD'])));
        $same->checkpoint(Scenario::dt('2026-10-01'));
        $this->assertNotSame('BLUE_DIAMOND', $plan->bySeq($same->highest['X'])->code); // G11
    }

    public function test_ranks_never_decrease_on_refund_and_skipped_ranks_awarded(): void
    {
        $s = (new Scenario)->consultant('X')->consultant('L1', 'X')->consultant('L2', 'X');
        $s->sale('L1', 1300, on: '2026-10-05');
        $s->sale('L2', 1300, on: '2026-10-06');
        $s->sale('L1', -1000, on: '2026-10-25');
        $r = $s->run();
        $this->assertSame('CRYSTAL', $r->stats['X']->achievedRank);
        self::assertDec(1600, $r->stats['X']->groupPrClosing);
        $awarded = [];
        foreach ($r->rankAwards as $a) {
            if ($a->status === 'awarded') {
                $awarded[] = "{$a->partnerId}:".$a->amount->toScale(2);
            }
        }
        $this->assertSame(['X:200.00'], $awarded);
    }

    // --- attività ---------------------------------------------------------------------------

    /** @return callable(string,string,string,?string):array */
    private function act(array $policies = [], string $since = '2026-10-05', string $status = 'active'): callable
    {
        $plan = PlanLoader::prelaunch($policies);
        $node = new Node('X', null, Scenario::dt($since), $status);

        return function (string $period, string $pts, string $prev = '0', ?string $first = null) use ($plan, $node): array {
            $prior = new PriorState(activityPointsPrev: ['X' => Num::of($prev)], firstActivityPeriod: $first ? ['X' => $first] : []);

            return Activity::evaluate($plan, PeriodKey::parse($period), $node, Num::of($pts), $prior);
        };
    }

    public function test_activity_first_month_then_first_time_then_reactivation(): void
    {
        $at = $this->act();
        [$ok, $why, $first] = $at('2026-10', '0');
        $this->assertTrue($ok);
        $this->assertStringContainsString('Primo mese', $why);
        $this->assertNull($first);
        [$ok, , $first] = $at('2026-11', '0');
        $this->assertFalse($ok);
        $this->assertNull($first);
        [$ok, , $first] = $at('2026-12', '120');
        $this->assertTrue($ok);
        $this->assertSame('2026-12', $first);
        [$ok, $why] = $at('2027-01', '0', '120', '2026-12');
        $this->assertTrue($ok);
        $this->assertStringContainsString('precedente', $why);
        $this->assertFalse($at('2027-02', '0', '0', '2026-12')[0]);
        $this->assertTrue($at('2027-03', '100', '0', '2026-12')[0]); // riattivazione
    }

    public function test_g18_activity_scenario(): void
    {
        $at = $this->act(since: '2026-10-05');
        [$ok, , $first] = $at('2026-10', '120');
        $this->assertTrue($ok);
        $this->assertSame('2026-10', $first);
        $this->assertTrue($at('2026-11', '0', '120', '2026-10')[0]); // vale anche il mese dopo
        $this->assertFalse($at('2026-12', '0', '0', '2026-10')[0]);
    }

    public function test_activity_previous_month_policy(): void
    {
        $at = $this->act(['activity_month_rule' => 'previous']);
        $this->assertTrue($at('2027-03', '0', '100', '2026-12')[0]);
        $this->assertFalse($at('2027-03', '500', '0', '2026-12')[0]);
    }

    public function test_suspended_is_not_active(): void
    {
        $this->assertFalse($this->act(status: 'suspended')('2027-03', '500', '0', '2026-12')[0]);
    }

    public function test_inactive_keeps_pr_and_rank(): void
    {
        $s = (new Scenario)->consultant('X', null, 'SAPPHIRE', active: false);
        $s->opening = ['X' => Num::of('9000')];
        $r = $s->run();
        $this->assertFalse($r->stats['X']->active);
        $this->assertSame('SAPPHIRE', $r->stats['X']->achievedRank);
        self::assertDec(9000, $r->stats['X']->groupPrClosing);
    }

    public function test_terminated_partner_downline_moves_to_active_ancestor(): void
    {
        // G20 (parte motore): Reg. art. 2.8, l'organizzazione passa al primo ascendente con contratto in essere
        $s = (new Scenario)
            ->consultant('A', null, 'DIAMOND')
            ->consultant('B', 'A', status: 'terminated')
            ->consultant('C', 'B', status: 'terminated')
            ->consultant('D', 'C');
        $s->sale('D', 100);
        $r = $s->run();
        $lp = array_map(fn (string $v) => (string) BigDecimal::of($v)->toScale(0), $r->stats['A']->linesPr);
        $this->assertSame(['B' => '0', 'C' => '0', 'D' => '100'], $lp);
        $this->assertSame(['A' => '15.00'], self::amounts($r, 'team'));
    }

    public function test_g20_three_directs_of_terminated_sponsor_chain_go_to_first_active_upline(): void
    {
        $s = (new Scenario)
            ->consultant('R')
            ->consultant('A', 'R', status: 'terminated')
            ->consultant('B', 'A', status: 'terminated')
            ->consultant('C1', 'B')->consultant('C2', 'B')->consultant('C3', 'B');
        $tree = new Tree(array_values($s->nodes));
        foreach (['C1', 'C2', 'C3'] as $c) {
            $this->assertSame('R', $tree->parentOf($c));
        }
        $this->assertSame(['C1', 'C2', 'C3'], array_values(array_diff($tree->childrenOf('R'), ['A', 'B'])));
    }

    public function test_g12_rank_reached_on_20th_effective_next_month_direct_uses_previous_rank(): void
    {
        $s = (new Scenario)->consultant('X');
        $s->opening = ['X' => Num::of('550')];
        $s->sale('X', 100, on: '2026-10-20 10:00');
        $r = $s->run();
        $pearl = array_values(array_filter($r->achievements, fn ($a) => $a->rankCode === 'PEARL'));
        $this->assertCount(1, $pearl);
        $this->assertSame('2026-11-01', $pearl[0]->effectiveFrom);
        $direct = array_values(array_filter($r->lines, fn (Line $l) => $l->bonus === 'direct'))[0];
        self::assertDec('15.00', $direct->amount);   // CONSULTANT 15%, non Pearl 18%
    }
}
