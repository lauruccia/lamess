<?php

declare(strict_types=1);

namespace App\Domain\Plan;

use App\Domain\Features\FeatureRegistry;
use App\Domain\Settings\SystemSettings;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * Interruttore «nascondi il vecchio piano di marketing» (piano a PR: PV, PVF, PVC, PR, Team, Matching, Rank Bonus, Fast Start).
 *
 * Nascondere NON cancella e NON ricalcola nulla: tabelle, movimenti, righe di commissione, periodi chiusi e versioni
 * del piano restano com'erano. Cambia solo ciò che si vede (menu, schede, colonne, grafici, area incaricato, documenti).
 * Gli importi storici dei bonus del vecchio piano si raccolgono sotto «Compensi del periodo precedente».
 * Rimostrare tutto è un click, nello stesso punto.
 */
final class LegacyPlan
{
    public const SETTING = 'legacy_plan_hidden';

    /** Etichetta neutra con cui compaiono gli importi dei bonus del vecchio piano quando è nascosto. */
    public const NEUTRAL_LABEL = 'Compensi del periodo precedente';

    /** Tipi di commissione che esistono solo nel vecchio piano (direct c'è anche nel nuovo; indirect, indirect_ext, structure sono nuovi). */
    public const BONUS_TYPES = ['team', 'matching', 'rank', 'rank_installment', 'fsb'];

    public static function hidden(): bool
    {
        return (bool) config('lamess.'.self::SETTING, false);
    }

    public static function visible(): bool
    {
        return ! self::hidden();
    }

    /** Chi può nascondere/mostrare: solo il super_admin. */
    public static function canToggle(?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    public static function hide(?string $reason = null): void
    {
        self::set(true, $reason);
    }

    public static function show(?string $reason = null): void
    {
        self::set(false, $reason);
    }

    private static function set(bool $hidden, ?string $reason): void
    {
        abort_unless(self::canToggle(), 403);
        SystemSettings::update([self::SETTING => $hidden], $reason);
    }

    /** True se la funzionalità (o un suo genitore) è del vecchio piano e il vecchio piano è nascosto. */
    public static function hides(string $featureKey): bool
    {
        if (! self::hidden()) {
            return false;
        }
        $key = $featureKey;
        while ($key !== null) {
            $def = FeatureRegistry::get($key);
            if ($def === null) {
                return false;
            }
            if ($def['legacy'] ?? false) {
                return true;
            }
            $key = $def['parent'] ?? null;
        }

        return false;
    }

    /** Il tipo di bonus è del solo vecchio piano. */
    public static function isLegacyBonus(string $type): bool
    {
        return in_array($type, self::BONUS_TYPES, true);
    }

    /** Etichetta di un tipo di bonus: neutra se il vecchio piano è nascosto e il tipo è suo. */
    public static function bonusLabel(string $type, string $label): string
    {
        return self::hidden() && self::isLegacyBonus($type) ? self::NEUTRAL_LABEL : $label;
    }

    /**
     * Somma in un'unica voce neutra i tipi del vecchio piano (solo quando è nascosto).
     *
     * @param  array<string, string|float|int>  $byType  tipo => importo
     * @return array<string, string|float|int>
     */
    public static function collapseBonusTypes(array $byType): array
    {
        if (! self::hidden()) {
            return $byType;
        }
        $out = [];
        $neutral = 0.0;
        $any = false;
        foreach ($byType as $type => $amount) {
            if (self::isLegacyBonus((string) $type)) {
                $neutral += (float) $amount;
                $any = true;
            } else {
                $out[$type] = $amount;
            }
        }
        if ($any) {
            $out[self::NEUTRAL_LABEL] = number_format($neutral, 2, '.', '');
        }

        return $out;
    }
}
