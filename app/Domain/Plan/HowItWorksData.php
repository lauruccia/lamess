<?php

declare(strict_types=1);

namespace App\Domain\Plan;

use App\Domain\Career\RankPalette;
use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Plan\PlanLoader;
use App\Models\CompPlanVersion;
use Brick\Math\BigDecimal;
use Throwable;

/**
 * Dati pubblici del piano Incaricati per la pagina «Come funziona» (sola lettura).
 *
 * Legge il piano in vigore, quindi scaglioni, livelli e requisiti restano quelli modificati dal pannello. Se il piano
 * in vigore non è quello Incaricati (o non c'è), usa il file di piano Incaricati del repository: la pagina deve sempre
 * mostrare il piano nuovo. Le percentuali sono punti percentuali, solo per la visualizzazione.
 */
final class HowItWorksData
{
    /** @return array<string, mixed> */
    public static function build(): array
    {
        $plan = self::plan();
        $k = $plan->kosmos;

        $tiers = [];
        foreach ($k->directTiers as $t) {
            $tiers[] = ['clients' => $t['min_clients'], 'pct' => self::pct($t['rate'])];
        }
        $levels = [];
        foreach ($k->indirectLevels as $l) {
            $levels[] = ['level' => $l['level'], 'pct' => self::pct($l['rate']), 'clients' => $l['min_clients'], 'pearl' => $l['min_pearl']];
        }

        $names = [];
        foreach ($plan->ranks as $r) {
            $names[$r->code] = $r->name;
        }
        $ranks = [];
        foreach ($plan->ranks as $i => $r) {
            if ($r->sequence === 0) {
                continue;
            }
            $parts = [];
            if ($r->minClients > 0 && $r->requires('clients')) {
                $parts[] = $r->minClients.($r->minClients === 1 ? ' cliente' : ' clienti');
            }
            if ($r->minPearlFirstLevel > 0 && $r->requires('pearl')) {
                $parts[] = $r->minPearlFirstLevel.' '.($r->minPearlFirstLevel === 1 ? 'incaricato diretto' : 'incaricati diretti').' almeno '.($names[$k->pearlRank] ?? 'Pearl');
            }
            if ($r->requires('structure')) {
                foreach ($r->structure as $s) {
                    $parts[] = $s->count.' '.($names[$s->rankCode] ?? RankPalette::label($s->rankCode));
                }
            }
            if ($r->minColumns > 0 && $r->requires('columns')) {
                $parts[] = $r->minColumns.' linee da '.$r->minColumnClients.' clienti';
            }
            $ranks[] = [
                'simple' => ! (($r->requires('structure') && $r->structure !== []) || ($r->requires('columns') && $r->minColumns > 0)),
                'code' => $r->code, 'name' => $r->name, 'color' => RankPalette::color($r->code, $i),
                'clients' => $r->requires('clients') ? $r->minClients : 0,
                'pearl' => $r->requires('pearl') ? $r->minPearlFirstLevel : 0,
                'text' => $parts === [] ? 'Nessun requisito' : implode(' · ', $parts),
            ];
        }

        $fee = null;
        if ((bool) config('lamess.annual_fee_enabled')) {
            $amount = (string) config('lamess.annual_fee_amount');
            $fee = [
                'amount' => str_replace('.', ',', rtrim(rtrim(number_format((float) $amount, 2, '.', ''), '0'), '.')),
                'from_year' => (int) config('lamess.annual_fee_first_paid_year'),
            ];
        }

        return [
            'tiers' => $tiers,
            'levels' => $levels,
            'ranks' => $ranks,
            'pearlCode' => $k->pearlRank,
            'clientRule' => $k->clientRule,
            'clientMin' => self::money($k->clientMinAmount),
            'clientMinValue' => $k->clientMinAmount->toFloat(),
            'vapShare' => $k->vapShare->toFloat(),
            'fee' => $fee,
            'showEuro' => (bool) config('lamess.how_it_works_show_euro', false),
        ];
    }

    private static function plan(): PlanConfig
    {
        try {
            $inForce = CompPlanVersion::inForce()?->plan();
            if ($inForce !== null && $inForce->kosmos !== null) {
                return $inForce;
            }
        } catch (Throwable) {
            // nessun piano leggibile in vigore: si usa il file del repository
        }

        return PlanLoader::incaricati();
    }

    private static function pct(BigDecimal $rate): float
    {
        return $rate->multipliedBy(100)->toFloat();
    }

    private static function money(BigDecimal $v): string
    {
        return rtrim(rtrim(number_format($v->toFloat(), 2, ',', '.'), '0'), ',');
    }
}
