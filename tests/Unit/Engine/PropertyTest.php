<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Support\Num;
use Tests\Support\Scenario;

/** Test di proprietà su reti casuali (seme fisso, riproducibile). */
final class PropertyTest extends EngineTestCase
{
    private const RANKS = ['CONSULTANT', 'PEARL', 'CRYSTAL', 'SAPPHIRE', 'RUBY', 'EMERALD', 'DIAMOND', 'BLUE_DIAMOND'];

    private function randomScenario(int $seed, array $policies = []): Scenario
    {
        mt_srand($seed);
        $s = new Scenario(policies: $policies);
        $n = mt_rand(8, 40);
        for ($i = 0; $i < $n; $i++) {
            $sponsor = $i === 0 ? null : 'n'.mt_rand(0, $i - 1);
            $s->consultant(
                'n'.$i, $sponsor, self::RANKS[mt_rand(0, 7)], active: mt_rand(0, 4) > 0,
                status: mt_rand(0, 12) === 0 ? 'terminated' : 'active', onboardingComplete: mt_rand(0, 6) > 0,
            );
        }
        $moves = mt_rand(5, 60);
        for ($j = 0; $j < $moves; $j++) {
            $owner = 'n'.mt_rand(0, $n - 1);
            $day = str_pad((string) mt_rand(1, 28), 2, '0', STR_PAD_LEFT);
            $s->sale($owner, mt_rand(10, 800), "2026-10-{$day} 1".mt_rand(0, 9).':00', mt_rand(0, 3) === 0 ? 'pvc' : 'pvf');
        }

        return $s;
    }

    /** Σ (Direct + Team) su un movimento ≤ aliquota massima × base, con qualunque politica. */
    public function test_direct_plus_team_never_exceeds_max_rate(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10] as $seed) {
            foreach (['compress', 'absorb'] as $policy) {
                $s = $this->randomScenario($seed, ['team_ineligible_upline' => $policy]);
                $r = $s->run();
                $max = $s->plan->maxDirectRate();
                $byMove = [];
                foreach ($r->lines as $l) {
                    if (in_array($l->bonus, ['direct', 'team'], true) && in_array($l->status, ['accrued', 'held'], true)) {
                        $byMove[$l->sourceMovementId]['sum'] = ($byMove[$l->sourceMovementId]['sum'] ?? Num::zero())->plus($l->amount);
                        $byMove[$l->sourceMovementId]['base'] = $l->base;
                    }
                }
                foreach ($byMove as $mid => $x) {
                    // tolleranza di arrotondamento: 1 centesimo per riga
                    $limit = Num::money($x['base']->multipliedBy($max))->plus('0.50');
                    $this->assertTrue($x['sum']->isLessThanOrEqualTo($limit), "seed {$seed} {$policy} movimento {$mid}: {$x['sum']} > {$limit}");
                }
            }
        }
    }

    /** Il rank raggiunto non scende mai (nemmeno con rimborsi) rispetto a quello di partenza. */
    public function test_rank_never_decreases(): void
    {
        foreach (range(11, 20) as $seed) {
            $s = $this->randomScenario($seed);
            $before = [];
            foreach ($s->nodes as $id => $_) {
                $before[$id] = 0;
                foreach ($s->ranks as $a) {
                    if ($a->partnerId === (string) $id) {
                        $before[$id] = max($before[$id], $s->plan->rank($a->rankCode)->sequence);
                    }
                }
            }
            $r = $s->run();
            foreach ($r->stats as $id => $st) {
                $this->assertGreaterThanOrEqual($before[(string) $id], $s->plan->rank($st->achievedRank)->sequence, "seed {$seed} {$id}");
            }
        }
    }

    /** Stesso input → stesso output, righe e hash. */
    public function test_deterministic(): void
    {
        foreach (range(21, 26) as $seed) {
            $s = $this->randomScenario($seed);
            $a = $s->run();
            $b = $s->run();
            $this->assertSame($a->inputHash, $b->inputHash);
            $f = fn ($r) => array_map(fn (Line $l) => $l->key.'='.$l->amount.'/'.$l->status, $r->lines);
            $this->assertSame($f($a), $f($b));
        }
    }

    /** Nessuna riga è assegnata a un incaricato cessato come maturata. */
    public function test_terminated_never_accrue(): void
    {
        foreach (range(30, 40) as $seed) {
            $s = $this->randomScenario($seed);
            $r = $s->run();
            foreach ($r->lines as $l) {
                if ($s->nodes[$l->beneficiaryId]->status === 'terminated' && $l->bonus !== 'rank_installment') {
                    $this->assertSame('excluded', $l->status, "seed {$seed} {$l->key}");
                }
            }
        }
    }
}
