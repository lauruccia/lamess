<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\PeriodResult;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;
use Tests\Support\Scenario;

/** Scenari del PMK 070726 e degli appunti di Laura (01–02/10/2026): attività solo PVF, acquisti personali, Fast Start Bonus. */
final class Pmk070726Test extends EngineTestCase
{
    /** @return array<string, string> totale FSB per beneficiario */
    private static function fsb(PeriodResult $r): array
    {
        $out = [];
        foreach ($r->lines as $l) {
            if ($l->bonus === 'fsb') {
                $out[$l->beneficiaryId] = (string) BigDecimal::of($out[$l->beneficiaryId] ?? 0)->plus($l->amount)->toScale(2);
            }
        }

        return $out;
    }

    private function fsbScenario(): Scenario
    {
        $s = new Scenario('2026-10', ['activity_month_rule' => 'current']);
        $s->consultant('X', null, since: '2026-10-01 09:00');
        foreach (['D1', 'D2', 'D3', 'D4'] as $d) {
            $s->consultant($d, 'X', since: '2026-10-02 09:00');
        }

        return $s;
    }

    public function test_g27_personal_purchase_does_not_activate(): void
    {
        $s = new Scenario('2026-10', ['activity_month_rule' => 'current']);
        $s->consultant('C', null, since: '2025-01-01', active: false);
        $s->sale('C', 100, kind: 'pvc');
        $r = $s->run();
        $this->assertFalse($r->stats['C']->active);
    }

    public function test_g41_personal_points_count_in_pr(): void
    {
        $s = new Scenario('2026-10', ['activity_month_rule' => 'current']);
        $s->consultant('C', null, since: '2025-01-01', active: false);
        $s->sale('C', 100, kind: 'pvc');
        self::assertDec(100, $s->run()->stats['C']->personalPrClosing);
    }

    public function test_activity_counts_only_customer_points_by_default(): void
    {
        $s = new Scenario('2026-10', ['activity_month_rule' => 'current']);
        $s->consultant('C', null, since: '2025-01-01', active: false);
        $s->consultant('F', null, since: '2025-01-01', active: false);
        $s->sale('C', 60, kind: 'pvf');
        $s->sale('C', 60, kind: 'pvc');
        $s->sale('F', 100, kind: 'pvf');
        $r = $s->run();
        $this->assertFalse($r->stats['C']->active, '60 PVF + 60 PVC personali: non bastano');
        $this->assertTrue($r->stats['F']->active);
    }

    public function test_g34_g35_differential_on_client_sale(): void
    {
        $s = (new Scenario)->consultant('DIA', null, 'DIAMOND')->consultant('RUB', 'DIA', 'RUBY')->consultant('NEW', 'RUB');
        $s->sale('NEW', 100, kind: 'pvf');
        $r = $s->run();
        $this->assertSame(['NEW' => '15.00'], self::amounts($r, 'direct'));
        $this->assertSame(['DIA' => '4.00', 'RUB' => '11.00'], self::amounts($r, 'team'));
    }

    public function test_g15_fast_start_steps_1_to_3_pay_440(): void
    {
        $s = $this->fsbScenario();
        $s->sale('D1', 100, '2026-10-03 10:00');
        $s->sale('D2', 100, '2026-10-03 11:00');
        $s->sale('X', 100, '2026-10-04 10:00');
        $s->sale('D3', 100, '2026-10-06 10:00');
        $s->sale('D4', 100, '2026-10-09 10:00');
        foreach ([['D1', 300], ['D2', 300], ['D3', 300], ['D4', 100]] as [$d, $pv]) {
            $s->sale($d, $pv, '2026-10-20 10:00');
        }
        $r = $s->run();
        $this->assertSame(['X' => '440.00'], self::fsb($r));
        $steps = array_values(array_map(fn ($l) => $l->key, array_filter($r->lines, fn ($l) => $l->bonus === 'fsb')));
        $this->assertSame(['fsb:X:1', 'fsb:X:2', 'fsb:X:3'], $steps);
    }

    public function test_g16_step_reached_after_deadline_is_lost_next_one_still_paid(): void
    {
        $s = $this->fsbScenario();
        $s->sale('D1', 100, '2026-10-10 10:00'); // giorno 10: step 1 (7 giorni) perso
        $s->sale('D2', 100, '2026-10-10 11:00');
        $s->sale('X', 100, '2026-10-11 10:00');
        $s->sale('D3', 100, '2026-10-12 10:00');
        $s->sale('D4', 100, '2026-10-13 10:00');
        $this->assertSame(['X' => '100.00'], self::fsb($s->run()));
    }

    public function test_g17_fifty_percent_rule_on_fsb_step_2(): void
    {
        $s = new Scenario('2026-10', ['activity_month_rule' => 'current']);
        $s->consultant('X', null, since: '2026-10-01 09:00');
        foreach (['D1', 'D2', 'D3'] as $d) {
            $s->consultant($d, 'X', since: '2026-10-02 09:00');
        }
        $s->consultant('D1a', 'D1', since: '2026-10-02 10:00');
        $s->sale('D1', 100, '2026-10-03 10:00');
        $s->sale('D1a', 300, '2026-10-03 11:00'); // linea D1 = 400 PV
        $s->sale('D2', 100, '2026-10-03 12:00');
        $s->sale('D3', 100, '2026-10-03 13:00');
        // totale 600 ≥ 500, ma 250 + 100 + 100 = 450 < 500: step 2 non raggiunto; step 1 sì (2+ diretti, 200 PV)
        $this->assertSame(['X' => '40.00'], self::fsb($s->run()));
    }

    public function test_g43_direct_below_100_pv_does_not_count(): void
    {
        $s = new Scenario('2026-10', ['activity_month_rule' => 'current']);
        $s->consultant('X', null, since: '2026-10-01 09:00');
        $s->consultant('D1', 'X', since: '2026-10-02 09:00');
        $s->consultant('D2', 'X', since: '2026-10-02 09:00');
        $s->sale('D1', 100, '2026-10-03 10:00');
        $s->sale('D2', 40, '2026-10-03 11:00');
        $s->sale('X', 60, '2026-10-03 12:00');
        $this->assertSame([], self::fsb($s->run()));
    }

    public function test_fsb_step_already_paid_in_closed_period_is_not_paid_again(): void
    {
        $s = $this->fsbScenario();
        $s->sale('D1', 100, '2026-10-03 10:00');
        $s->sale('D2', 100, '2026-10-03 11:00');
        $this->assertSame([], self::fsb($s->run(['fsbAwards' => ['X:1']])));
    }

    public function test_fsb_counts_movements_of_closed_periods(): void
    {
        // registrazione a settembre, vendite a settembre (periodo chiuso) e ottobre: il premio è di ottobre solo se il requisito si completa
        $s = new Scenario('2026-10', ['activity_month_rule' => 'current']);
        $s->consultant('X', null, since: '2026-09-28 09:00');
        $s->consultant('D1', 'X', since: '2026-09-29 09:00');
        $s->consultant('D2', 'X', since: '2026-09-29 09:00');
        $old1 = new Movement('p1', 'D1', 'pvf', Num::of(100), Num::of(100), Scenario::dt('2026-09-30 10:00'));
        $s->sale('D2', 100, '2026-10-02 10:00');
        $r = $s->run(['fsbMovements' => [$old1]]);
        $this->assertSame(['X' => '40.00'], self::fsb($r));
    }

    public function test_g_step10_needs_step9_and_three_distinct_lines(): void
    {
        $s = new Scenario('2026-10', ['activity_month_rule' => 'current']);
        $s->consultant('X', null, since: '2026-03-01');
        foreach (['A', 'B', 'C'] as $l) {
            $s->consultant($l, 'X', since: '2026-03-02');
            $s->consultant($l.'1', $l, since: '2026-03-10');
        }
        $awards = ['X:9', 'A1:9', 'B1:9', 'C1:9'];
        $r = $s->run(['fsbAwards' => $awards]);
        $this->assertSame(['X' => '12000.00'], self::fsb($r));
        // solo due linee: niente
        $r2 = $s->run(['fsbAwards' => ['X:9', 'A1:9', 'B1:9']]);
        $this->assertSame([], self::fsb($r2));
        // già pagato
        $r3 = $s->run(['fsbAwards' => array_merge($awards, ['X:10'])]);
        $this->assertSame([], self::fsb($r3));
    }

    public function test_fsb_disabled_by_setting(): void
    {
        $s = $this->fsbScenario();
        $s->sale('D1', 100, '2026-10-03 10:00');
        $s->sale('D2', 100, '2026-10-03 11:00');
        $plan = PlanConfig::fromArray(array_replace(
            json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/plan-prelaunch-2026-07-07.json'), true),
            ['settings' => ['fsb_enabled' => 'false']],
        ));
        $s->plan = $plan;
        $this->assertSame([], self::fsb($s->run()));
    }
}
