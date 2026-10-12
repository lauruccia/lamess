<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\Movement;
use App\Domain\Engine\Support\Num;
use Brick\Math\RoundingMode;

/** Storno di compensi per un reso su un periodo chiuso (D11): riga negativa nel periodo aperto. */
final class ClawbackCalculator
{
    /** @return list<Line>|null null = non è uno storno di periodo chiuso */
    public static function calculate(BonusContext $ctx, Movement $m): ?array
    {
        $origLines = $ctx->prior->originalLines[$m->reversesId ?? ''] ?? [];
        $orig = $ctx->prior->originalMovements[$m->reversesId ?? ''] ?? null;
        if ($orig === null) {
            return null;
        }
        if ($ctx->plan->policies->is('refund_after_close', 'no_clawback')) {
            $ctx->warnings[] = "Storno {$m->id}: nessun recupero compensi (politica D11)";

            return [];
        }
        if ($orig->pv->isZero()) {
            return [];
        }
        $ratio = $m->pv->dividedBy($orig->pv, 12, RoundingMode::HalfUp);
        $out = [];
        foreach ($origLines as $ol) {
            if (! in_array($ol->bonus, ['direct', 'team', 'indirect', 'indirect_ext'], true)) {
                continue;
            }
            $out[] = new Line(
                key: "clawback:{$m->id}:{$ol->key}", bonus: 'clawback', beneficiaryId: $ol->beneficiaryId,
                amount: Num::money($ol->amount->multipliedBy($ratio)), base: $ol->amount,
                sourceMovementId: $m->id, sourcePartnerId: $m->ownerId,
                explanation: [
                    'reverses_line' => $ol->key, 'original_bonus' => $ol->bonus, 'original_period' => $ol->periodCode,
                    'formula' => "{$ol->amount} × ({$m->pv} / {$orig->pv})",
                ],
                decisions: ['D11'],
            );
        }

        return $out;
    }
}
