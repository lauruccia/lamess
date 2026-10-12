<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Model\PriorState;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Support\Num;
use App\Domain\Engine\Support\PeriodKey;
use Brick\Math\BigDecimal;

/** Contesto condiviso dai calcolatori dei bonus di un periodo. */
final class BonusContext
{
    /**
     * @param  array<string, bool>  $active
     * @param  array<string, int>  $eff  sequenza del rank efficace (paid-as)
     * @param  list<string>  $warnings
     */
    public function __construct(
        public readonly PlanConfig $plan,
        public readonly PeriodKey $period,
        public readonly Tree $tree,
        public readonly CareerState $career,
        public readonly PriorState $prior,
        public readonly array $active,
        public readonly array $eff,
        public array $warnings = [],
    ) {}

    public function rate(string $id): BigDecimal
    {
        return $this->plan->bySeq($this->eff[$id])->directRate;
    }

    public function rankCode(string $id): string
    {
        return $this->plan->bySeq($this->eff[$id])->code;
    }

    /** @return array{0:string,1:string} [stato, motivo] */
    private function gate(string $id, string $minCode): array
    {
        $node = $this->tree->nodes[$id];
        if ($node->status === 'terminated') {
            return ['excluded', 'cessato'];
        }
        if (! $this->active[$id]) {
            return ['excluded', 'inattivo'];
        }
        if ($this->eff[$id] < $this->plan->rank($minCode)->sequence) {
            return ['excluded', "qualifica_inferiore_a_{$minCode}"];
        }
        if (! $node->onboardingComplete) {
            return $this->plan->policies->is('onboarding_incomplete_indirect', 'not_accrued')
                ? ['excluded', 'onboarding_incompleto']
                : ['held', 'onboarding_incompleto'];
        }
        if ($node->bonusHold) {
            return ['held', 'provvedimento_trattenuta'];
        }

        return ['accrued', ''];
    }

    /** @return array{0:string,1:string} */
    public function indirectStatus(string $id): array
    {
        return $this->gate($id, $this->plan->indirectMinRank);
    }

    /** @return array{0:string,1:string} */
    public function matchingStatus(string $id): array
    {
        return $this->gate($id, $this->plan->matchingMinRank);
    }

    /** @return array{0:string,1:string} */
    public function directStatus(string $id): array
    {
        $node = $this->tree->nodes[$id];
        if ($node->status === 'terminated') {
            return ['excluded', 'cessato'];
        }
        if ($node->bonusHold) {
            return ['held', 'provvedimento_trattenuta'];
        }

        return ['accrued', ''];
    }

    public function baseAmount(Movement $m): BigDecimal
    {
        $pts = $this->plan->policies->is('commission_base', 'cv') ? $m->cv : $m->pv;

        return Num::money($pts->multipliedBy($this->plan->commissionBasePerPv));
    }
}
