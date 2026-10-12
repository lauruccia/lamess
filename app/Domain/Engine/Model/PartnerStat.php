<?php

declare(strict_types=1);

namespace App\Domain\Engine\Model;

use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;

final class PartnerStat
{
    public BigDecimal $personalPvf;

    public BigDecimal $personalPvc;

    public BigDecimal $personalCv;

    public BigDecimal $groupPv;

    public BigDecimal $activityPoints;

    public BigDecimal $personalPrOpening;

    public BigDecimal $personalPrClosing;

    public BigDecimal $groupPrClosing;

    public bool $active = false;

    public string $activeReason = '';

    /** Periodo (YYYY-MM) in cui è stata raggiunta per la prima volta la soglia di attività. */
    public ?string $firstActivityPeriod = null;

    public string $achievedRank = '';

    public string $effectiveRank = '';

    public string $indirectStatus = '';

    public string $matchingStatus = '';

    /** @var array<string, mixed> */
    public array $nextRank = [];

    /** @var array<string, string> */
    public array $linesPr = [];

    public function __construct(public string $partnerId, ?BigDecimal $personalPrOpening = null)
    {
        $this->personalPvf = $this->personalPvc = $this->personalCv = $this->groupPv = Num::zero();
        $this->activityPoints = $this->personalPrClosing = $this->groupPrClosing = Num::zero();
        $this->personalPrOpening = $personalPrOpening ?? Num::zero();
    }
}
