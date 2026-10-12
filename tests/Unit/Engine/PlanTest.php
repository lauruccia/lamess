<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Engine\Policy\Policies;
use App\Domain\Engine\Policy\PolicySpecs;
use App\Domain\Engine\Support\Num;
use InvalidArgumentException;

/** Seeder/valori ufficiali del Piano Pre-Lancio (G25) e validazione della configurazione. */
final class PlanTest extends EngineTestCase
{
    public function test_g25_rank_bonus_sum_and_fsb_sums(): void
    {
        $plan = PlanLoader::prelaunch();
        self::assertDec('522500', Num::sum(array_map(fn ($r) => $r->rankBonus, $plan->ranks)));
        $fsb = array_values(array_filter($plan->fsbSteps, fn ($s) => $s->number <= 9));
        self::assertDec('38440', Num::sum(array_map(fn ($s) => $s->amount, $fsb)));
        self::assertDec('440', Num::sum(array_map(fn ($s) => $s->amount, array_slice($fsb, 0, 3))));
        self::assertDec('50440', Num::sum(array_map(fn ($s) => $s->amount, $plan->fsbSteps)));
    }

    public function test_team_max_is_direct_rate_minus_entry_rate(): void
    {
        $plan = PlanLoader::prelaunch();
        $expected = ['PEARL' => '0.03', 'CRYSTAL' => '0.06', 'SAPPHIRE' => '0.09', 'RUBY' => '0.11', 'EMERALD' => '0.13',
            'DIAMOND' => '0.15', 'BLUE_DIAMOND' => '0.17', 'ROSE_DIAMOND' => '0.19', 'PURPLE_DIAMOND' => '0.21',
            'RED_DIAMOND' => '0.22', 'BLACK_DIAMOND' => '0.23'];
        foreach ($expected as $code => $team) {
            self::assertDec($team, $plan->rank($code)->directRate->minus($plan->entryRank()->directRate), $code);
        }
        self::assertDec('0.38', $plan->maxDirectRate());
    }

    public function test_official_values(): void
    {
        $plan = PlanLoader::prelaunch();
        $this->assertCount(12, $plan->ranks);
        self::assertDec('100', $plan->activityThreshold);
        self::assertDec('50000', $plan->rankBonusInstallmentThreshold);
        $this->assertSame(12, $plan->rankBonusInstallments);
        $this->assertSame(6, $plan->matchingDepth());
        self::assertDec('0.05', $plan->matchingRate('SAPPHIRE', 1));
        self::assertDec('0.10', $plan->matchingRate('BLACK_DIAMOND', 3));
        self::assertDec('0', $plan->matchingRate('RUBY', 3));
        $this->assertTrue($plan->fsbEnabled);
    }

    public function test_every_parameter_is_documented_in_registry(): void
    {
        $specs = PolicySpecs::all();
        foreach ($specs as $name => $s) {
            $this->assertNotSame('', $s['label'], $name);
            $this->assertNotSame('', $s['source'], $name);
            if ($s['options'] !== []) {
                $this->assertArrayHasKey((string) $s['default'], $s['options'], "default di {$name}");
            }
        }
        $this->assertSame(array_keys($specs), array_keys(PolicySpecs::defaults()));
    }

    public function test_unknown_policy_and_invalid_value_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Policies(['non_esiste' => 'x']);
    }

    public function test_invalid_policy_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Policies(['commission_base' => 'boh']);
    }

    public function test_plan_validation_rejects_unknown_structure_rank(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/plan-prelaunch-2026-07-07.json'), true);
        $data['ranks'][2]['structure'][0]['rank'] = 'FANTASMA';
        $this->expectException(InvalidArgumentException::class);
        PlanConfig::fromArray($data);
    }

    public function test_changing_a_parameter_changes_the_result_without_touching_code(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3).'/config/lamess/plan-prelaunch-2026-07-07.json'), true);
        $data['settings']['activity_threshold'] = '150';
        $plan = PlanConfig::fromArray($data);
        self::assertDec('150', $plan->activityThreshold);
    }
}
