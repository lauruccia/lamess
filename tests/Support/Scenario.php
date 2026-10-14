<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Engine\Calc\PeriodCalculator;
use App\Domain\Engine\Model\AchievedRank;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\Node;
use App\Domain\Engine\Model\PeriodInput;
use App\Domain\Engine\Model\PeriodResult;
use App\Domain\Engine\Model\PriorState;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/** Mini-DSL per costruire reti e movimenti leggibili nei test del motore. */
final class Scenario
{
    /** Nei test che non riguardano l'attività un incaricato «attivo» ha 100 punti nel mese precedente. */
    public const NEUTRAL_ACTIVITY = ['activity_month_rule' => 'current_or_previous'];

    public PeriodKey $period;

    public PlanConfig $plan;

    /** @var array<string, Node> */
    public array $nodes = [];

    /** @var list<Movement> */
    public array $moves = [];

    /** @var list<AchievedRank> */
    public array $ranks = [];

    /** @var array<string, BigDecimal> */
    public array $opening = [];

    /** @var array<string, BigDecimal> */
    public array $prevPoints = [];

    /** @var array<string, string> */
    public array $firstActivity = [];

    private int $seq = 0;

    /** @param array<string, string|bool> $policies */
    public function __construct(string $period = '2026-10', array $policies = [], ?PlanConfig $plan = null)
    {
        $this->period = PeriodKey::parse($period);
        $this->plan = $plan ?? PlanLoader::prelaunch(array_replace(self::NEUTRAL_ACTIVITY, $policies));
    }

    public static function dt(string $s): DateTimeImmutable
    {
        return PeriodKey::at($s);
    }

    public function consultant(
        string|int $id, string|int|null $sponsor = null, string $rank = 'CONSULTANT', string $since = '2025-01-01',
        bool $active = true, string $rankAt = '2025-06-15', string $status = 'active', bool $onboardingComplete = true,
        bool $bonusHold = false, ?string $rankCap = null, ?string $rankHoldUntil = null, ?string $previousRank = null,
    ): self {
        $id = (string) $id;
        $this->nodes[$id] = new Node($id, $sponsor, self::dt($since), $status, $onboardingComplete, $bonusHold, $rankCap, $id, $rankHoldUntil, $previousRank);
        if ($rank !== 'CONSULTANT') {
            $seq = $this->plan->rank($rank)->sequence;
            foreach ($this->plan->ranks as $r) {
                if ($r->sequence >= 1 && $r->sequence <= $seq) {
                    $this->ranks[] = new AchievedRank($id, $r->code, self::dt($rankAt));
                }
            }
        }
        $this->firstActivity[$id] = '2025-01';
        $this->prevPoints[$id] = Num::of($active ? '100' : '0');

        return $this;
    }

    public function sale(
        string|int $owner, int|string $pv, string $on = '2026-10-10 12:00', string $kind = 'pvf',
        int|string|null $cv = null, ?string $reverses = null, ?string $mid = null,
    ): Movement {
        $this->seq++;
        $m = new Movement(
            $mid ?? "m{$this->seq}", $owner, $kind, Num::of((string) $pv), Num::of((string) ($cv ?? $pv)),
            self::dt($on), $reverses,
        );
        $this->moves[] = $m;

        return $m;
    }

    public function prior(array $overrides = []): PriorState
    {
        return (new PriorState(
            personalPrOpening: $this->opening,
            achievedRanks: $this->ranks,
            activityPointsPrev: $this->prevPoints,
            firstActivityPeriod: $this->firstActivity,
        ))->with($overrides);
    }

    public function input(array $priorOverrides = []): PeriodInput
    {
        return new PeriodInput($this->period, $this->plan, array_values($this->nodes), $this->moves, $this->prior($priorOverrides));
    }

    public function run(array $priorOverrides = []): PeriodResult
    {
        return PeriodCalculator::calculate($this->input($priorOverrides));
    }
}
