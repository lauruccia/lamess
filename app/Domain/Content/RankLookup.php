<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Models\CompPlanVersion;
use App\Models\Consultant;
use App\Models\RankAchievement;

/** Qualifica attuale di un incaricato, letta dal piano in vigore. */
final class RankLookup
{
    /** @return array{code: string, name: string, sequence: int}|null */
    public static function current(Consultant $c): ?array
    {
        $ranks = collect(CompPlanVersion::inForce()?->data['ranks'] ?? [])->keyBy('code');
        $best = null;
        $codes = RankAchievement::where('consultant_id', $c->id)->where('effective_from', '<=', now()->toDateString())->pluck('rank_code');
        foreach ($codes as $code) {
            $r = $ranks->get($code);
            if ($r !== null && ($best === null || (int) $r['sequence'] > $best['sequence'])) {
                $best = ['code' => $r['code'], 'name' => $r['name'], 'sequence' => (int) $r['sequence']];
            }
        }

        return $best;
    }

    public static function sequenceOf(string $code): ?int
    {
        foreach (CompPlanVersion::inForce()?->data['ranks'] ?? [] as $r) {
            if ($r['code'] === $code) {
                return (int) $r['sequence'];
            }
        }

        return null;
    }

    /** L’incaricato ha almeno la qualifica indicata? Qualifica sconosciuta al piano in vigore → no (si chiude, non si apre). */
    public static function atLeast(Consultant $c, string $code): bool
    {
        $need = self::sequenceOf($code);
        $have = self::current($c);

        return $need !== null && $have !== null && $have['sequence'] >= $need;
    }
}
