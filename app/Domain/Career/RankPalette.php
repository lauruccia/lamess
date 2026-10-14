<?php

namespace App\Domain\Career;

use App\Models\CompPlanVersion;

/**
 * Colore delle qualifiche: ognuna ha quello della sua pietra (perla, cristallo, zaffiro, rubino, smeraldo, diamante).
 * Lo usano l'albero del back-office e quello dell'area dell'incaricato.
 */
final class RankPalette
{
    /** Colore fisso per codice di qualifica. */
    public const BY_CODE = [
        'CONSULTANT' => '#BBB4AF', 'PEARL' => '#C8B6A6', 'CRYSTAL' => '#6EC6E6', 'SAPPHIRE' => '#0F52BA',
        'RUBY' => '#D0103A', 'EMERALD' => '#1E9E5A', 'DIAMOND' => '#AAB7C4', 'BLUE_DIAMOND' => '#2E86DE',
    ];

    /** Dal più basso al più alto, per le qualifiche senza una pietra nota. */
    public const FALLBACK = ['#B9B1A5', '#D9A27E', '#C2663A', '#A98625', '#6A8F58', '#4A7A9B', '#5B6FA8', '#8A5C8E', '#B04A6A', '#8C2F39', '#5A3A4F', '#2B2B2B'];

    public static function color(?string $code, int $position = 0): string
    {
        return self::BY_CODE[strtoupper((string) $code)] ?? self::FALLBACK[$position % count(self::FALLBACK)];
    }

    /** Nome da mostrare: solo la pietra, es. «Blue diamond». */
    public static function label(?string $code): string
    {
        return $code === null || $code === '' ? '' : ucfirst(strtolower(str_replace('_', ' ', $code)));
    }

    /**
     * Qualifiche del piano in vigore, dalla più bassa alla più alta, con il colore della pietra.
     *
     * @return list<array{code: string, name: string, color: string}>
     */
    public static function forPlan(): array
    {
        $out = [];
        foreach (CompPlanVersion::inForce()?->plan()->ranks ?? [] as $i => $rank) {
            $out[] = ['code' => $rank->code, 'name' => $rank->name, 'color' => self::color($rank->code, $i)];
        }

        return $out;
    }
}
