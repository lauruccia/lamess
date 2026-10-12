<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Colori di marca per i punti in cui un CSS non basta (grafici Chart.js, PDF).
 * Devono coincidere con public/css/lamess-tokens.css, che resta la fonte per HTML/CSS.
 */
final class Brand
{
    public const TERRACOTTA = '#C2663A';

    /** I 6 colori dei grafici (--lm-chart-1…6): ≥ 3:1 su sfondi chiari e scuri. */
    public const CHART = ['#C2663A', '#4A7A9B', '#6A8F58', '#A98625', '#8A5C8E', '#80776D'];

    /** @return list<string> $n colori dei grafici; oltre il sesto si ricomincia dal primo. */
    public static function chart(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::CHART[$i % count(self::CHART)];
        }

        return $out;
    }
}
