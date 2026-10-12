<?php

declare(strict_types=1);

namespace App\Domain\Engine\Calc;

use App\Domain\Engine\Model\Line;
use App\Domain\Engine\Model\PeriodResult;
use App\Domain\Engine\Support\Num;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Simulazione / impatto (RF-062): confronta due risultati dello stesso periodo calcolati con
 * piani diversi (attivo vs bozza). Non scrive nulla: lavora solo su PeriodResult in memoria.
 */
final class ImpactReport
{
    /**
     * @return array{totals: array<string, array{base:BigDecimal,alt:BigDecimal,diff:BigDecimal}>, by_partner: array<string, array{base:BigDecimal,alt:BigDecimal,diff:BigDecimal}>, beneficiaries: array{base:int,alt:int}}
     */
    public static function compare(PeriodResult $base, PeriodResult $alt): array
    {
        $totals = [];
        foreach (Line::BONUSES as $bonus) {
            $b = $base->total($bonus);
            $a = $alt->total($bonus);
            if (! $b->isZero() || ! $a->isZero()) {
                $totals[$bonus] = ['base' => $b, 'alt' => $a, 'diff' => $a->minus($b)];
            }
        }
        $ids = array_unique(array_merge(self::beneficiaries($base), self::beneficiaries($alt)));
        sort($ids, SORT_STRING);
        $by = [];
        foreach ($ids as $id) {
            $b = $base->earned($id);
            $a = $alt->earned($id);
            if (! $a->isEqualTo($b)) {
                $by[$id] = ['base' => $b, 'alt' => $a, 'diff' => $a->minus($b)];
            }
        }

        return [
            'totals' => $totals,
            'by_partner' => $by,
            'beneficiaries' => ['base' => count(self::beneficiaries($base)), 'alt' => count(self::beneficiaries($alt))],
        ];
    }

    /** payout ratio = commissioni / imponibile vendite (RF 9.11). */
    public static function payoutRatio(PeriodResult $r, BigDecimal|string $netSales): BigDecimal
    {
        $net = Num::of($netSales);
        if ($net->isZero()) {
            return Num::zero();
        }

        return $r->total()->dividedBy($net, 6, RoundingMode::HalfUp);
    }

    /** @return list<string> */
    private static function beneficiaries(PeriodResult $r): array
    {
        $ids = [];
        foreach ($r->lines as $l) {
            if (in_array($l->status, ['accrued', 'held'], true)) {
                $ids[$l->beneficiaryId] = true;
            }
        }

        return array_map('strval', array_keys($ids));
    }
}
