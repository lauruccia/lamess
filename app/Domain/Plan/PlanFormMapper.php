<?php

declare(strict_types=1);

namespace App\Domain\Plan;

use App\Domain\Engine\Plan\PlanConfig;
use App\Domain\Engine\Policy\PolicySpecs;

/**
 * Traduce il piano (array JSON del motore) nello stato del modulo «Parametri del piano» e ritorno.
 * Le parti non modificabili da modulo (label, FSB, fonti dei rank) si conservano dal piano di partenza.
 */
final class PlanFormMapper
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toForm(array $data): array
    {
        $settings = [];
        foreach (PlanConfig::SETTINGS as $k => $def) {
            $v = $data['settings'][$k] ?? $def[1];
            $settings[$k] = $k === 'fsb_enabled' ? filter_var($v, FILTER_VALIDATE_BOOLEAN) : (string) $v;
        }
        $policies = [];
        foreach (PolicySpecs::all() as $k => $spec) {
            $policies[$k] = $data['policies'][$k] ?? $spec['default'];
        }
        $ranks = array_map(static fn (array $r) => [
            'code' => $r['code'], 'name' => $r['name'], 'sequence' => (int) $r['sequence'], 'pr_required' => (string) $r['pr_required'],
            'fifty_percent_rule' => (bool) $r['fifty_percent_rule'], 'direct_pct' => (string) $r['direct_pct'], 'rank_bonus' => (string) ($r['rank_bonus'] ?? 0), 'min_clients' => (int) ($r['min_clients'] ?? 0), 'min_pearl_first_level' => (int) ($r['min_pearl_first_level'] ?? 0), 'min_columns' => (int) ($r['min_columns'] ?? 0), 'min_column_clients' => (int) ($r['min_column_clients'] ?? 0), 'disabled_requirements' => array_values($r['disabled_requirements'] ?? []),
            'structure' => array_map(static fn (array $s) => ['rank' => $s['rank'], 'count' => (int) $s['count']], $r['structure'] ?? []),
            'structure_text' => self::structureToText($r['structure'] ?? []),
        ], $data['ranks'] ?? []);
        $matching = [];
        foreach (($data['matching_pct'] ?? []) as $code => $levels) {
            $matching[] = ['rank' => $code, 'levels' => implode(', ', array_map('strval', $levels))];
        }

        $form = ['settings' => $settings, 'policies' => $policies, 'ranks' => array_values($ranks), 'matching' => $matching];
        if (isset($data['kosmos'])) {
            $k = $data['kosmos'];
            $form['kosmos'] = [
                'vap_share_pct' => (string) ($k['vap_share_pct'] ?? '66.66666'),
                'direct_tiers' => array_map(static fn (array $t) => ['min_clients' => (int) $t['min_clients'], 'pct' => (string) $t['pct']], $k['direct_tiers'] ?? []),
                'indirect_levels' => array_map(static fn (array $l) => [
                    'level' => (int) $l['level'], 'pct' => (string) $l['pct'], 'min_clients' => (int) ($l['min_clients'] ?? 0), 'min_pearl' => (int) ($l['min_pearl'] ?? 0),
                ], $k['indirect_levels'] ?? []),
                'structure_bonus' => collect($k['structure_bonus'] ?? [])->map(fn ($v, $c) => ['rank' => $c, 'amount' => (string) $v])->values()->all(),
                'extended_pct' => collect($k['extended_pct'] ?? [])->map(fn ($v, $c) => ['rank' => $c, 'pct' => (string) $v])->values()->all(),
                'structure_clients' => (int) ($k['structure_clients'] ?? 3),
                'structure_window_days' => (int) ($k['structure_window_days'] ?? 30),
                'extended_stop_levels' => (int) ($k['extended_stop_levels'] ?? 5),
                'extended_requires_level' => isset($k['extended_requires_level']) ? (string) $k['extended_requires_level'] : '',
                'pearl_rank' => (string) ($k['pearl_rank'] ?? 'PEARL'),
                'client_rule' => (string) ($k['client_rule'] ?? 'min_amount'),
                'client_min_amount' => (string) ($k['client_min_amount'] ?? '150'),
                'rank_grace_until' => (string) ($k['rank_grace_until'] ?? ''),
                'rank_grace_days' => (string) ($k['rank_grace_days'] ?? ''),
            ];
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $form
     * @param  array<string, mixed>  $base  piano di partenza (conserva label, FSB, fonti)
     * @return array<string, mixed>
     */
    public static function toData(array $form, array $base): array
    {
        $sources = collect($base['ranks'] ?? [])->mapWithKeys(fn ($r) => [$r['code'] => $r['source'] ?? ''])->all();
        $data = $base;
        $data['settings'] = [];
        foreach (PlanConfig::SETTINGS as $k => $def) {
            $v = $form['settings'][$k] ?? $base['settings'][$k] ?? $def[1];
            $data['settings'][$k] = is_bool($v) ? ($v ? 'true' : 'false') : trim((string) $v);
        }
        $data['policies'] = $base['policies'] ?? [];
        foreach (PolicySpecs::all() as $k => $spec) {
            if (array_key_exists($k, $form['policies'] ?? [])) {
                $v = is_bool($spec['default']) ? (bool) $form['policies'][$k] : (string) $form['policies'][$k];
                // Si scrivono le politiche già presenti nel piano o diverse dal default.
                if (array_key_exists($k, $base['policies'] ?? []) || $v !== $spec['default']) {
                    $data['policies'][$k] = $v;
                }
            }
        }
        $baseRanks = collect($base['ranks'] ?? [])->mapWithKeys(fn ($r) => [strtoupper((string) $r['code']) => $r])->all();
        $data['ranks'] = [];
        foreach (array_values($form['ranks'] ?? []) as $i => $r) {
            $code = strtoupper(trim((string) $r['code']));
            $b = $baseRanks[$code] ?? [];
            // I campi non mostrati nel modulo (es. PR e Direct % nel Piano Incaricati) restano quelli del piano di partenza.
            $pick = static fn (string $key, mixed $default = 0) => $r[$key] ?? $b[$key] ?? $default;
            if (isset($r['structure'])) {
                $structure = array_values(array_map(static fn (array $s) => ['rank' => strtoupper(trim((string) $s['rank'])), 'count' => (int) $s['count']], $r['structure']));
            } elseif (isset($r['structure_text'])) {
                $structure = self::textToStructure((string) $r['structure_text']);
            } else {
                $structure = $b['structure'] ?? [];
            }
            $data['ranks'][] = [
                'code' => $code, 'name' => trim((string) $pick('name', $code)), 'sequence' => (int) $pick('sequence', $i),
                'pr_required' => self::num($pick('pr_required')), 'fifty_percent_rule' => (bool) $pick('fifty_percent_rule', false),
                'direct_pct' => self::num($pick('direct_pct')), 'rank_bonus' => self::num($pick('rank_bonus')),
                'min_clients' => max(0, (int) $pick('min_clients')), 'min_pearl_first_level' => max(0, (int) $pick('min_pearl_first_level')),
                'min_columns' => max(0, (int) $pick('min_columns')), 'min_column_clients' => max(0, (int) $pick('min_column_clients')),
                'disabled_requirements' => array_values(array_intersect(['clients', 'pearl', 'structure', 'columns'], (array) $pick('disabled_requirements', []))),
                'structure' => $structure,
                'source' => $sources[$code] ?? 'Modificato da pannello',
            ];
        }
        if (array_key_exists('matching', $form)) {
            $data['matching_pct'] = [];
            foreach ($form['matching'] ?? [] as $m) {
                $levels = array_values(array_filter(array_map('trim', explode(',', str_replace(';', ',', (string) ($m['levels'] ?? '')))), 'strlen'));
                $data['matching_pct'][strtoupper(trim((string) $m['rank']))] = array_map(self::num(...), $levels);
            }
        }

        if (isset($form['kosmos'])) {
            $k = $form['kosmos'];
            $data['kosmos'] = ($base['kosmos'] ?? []) + [];
            $data['kosmos']['vap_share_pct'] = self::num($k['vap_share_pct'] ?? '66.66666');
            $data['kosmos']['direct_tiers'] = array_values(array_map(static fn (array $t) => ['min_clients' => (int) $t['min_clients'], 'pct' => self::num($t['pct'])], $k['direct_tiers'] ?? []));
            $data['kosmos']['indirect_levels'] = array_values(array_map(static fn (array $l) => [
                'level' => (int) $l['level'], 'pct' => self::num($l['pct']), 'min_clients' => (int) ($l['min_clients'] ?? 0), 'min_pearl' => (int) ($l['min_pearl'] ?? 0),
            ], $k['indirect_levels'] ?? []));
            $data['kosmos']['structure_bonus'] = [];
            foreach ($k['structure_bonus'] ?? [] as $row) {
                $data['kosmos']['structure_bonus'][strtoupper(trim((string) $row['rank']))] = self::num($row['amount']);
            }
            $data['kosmos']['extended_pct'] = [];
            foreach ($k['extended_pct'] ?? [] as $row) {
                $data['kosmos']['extended_pct'][strtoupper(trim((string) $row['rank']))] = self::num($row['pct']);
            }
            $data['kosmos']['structure_clients'] = (int) ($k['structure_clients'] ?? 3);
            $data['kosmos']['structure_window_days'] = (int) ($k['structure_window_days'] ?? 30);
            $data['kosmos']['extended_stop_levels'] = (int) ($k['extended_stop_levels'] ?? 5);
            $req = trim((string) ($k['extended_requires_level'] ?? ''));
            $data['kosmos']['extended_requires_level'] = $req === '' ? null : (int) $req;
            $data['kosmos']['pearl_rank'] = strtoupper(trim((string) ($k['pearl_rank'] ?? 'PEARL')));
            $data['kosmos']['client_rule'] = in_array($k['client_rule'] ?? null, ['any_order', 'min_amount'], true) ? $k['client_rule'] : 'min_amount';
            $data['kosmos']['client_min_amount'] = self::num($k['client_min_amount'] ?? '150');
            $grace = trim((string) ($k['rank_grace_until'] ?? ''));
            $data['kosmos']['rank_grace_until'] = $grace === '' ? null : $grace;
            $days = trim((string) ($k['rank_grace_days'] ?? ''));
            $data['kosmos']['rank_grace_days'] = $days === '' ? null : (int) $days;
            unset($data['kosmos']['client_min_pv']);
        }

        return $data;
    }

    /** @param  list<array{rank: string, count: int|string}>  $structure */
    public static function structureToText(array $structure): string
    {
        return implode(', ', array_map(static fn (array $s) => $s['rank'].':'.(int) $s['count'], $structure));
    }

    /**
     * «CRYSTAL:2, RUBY:2» (anche «CRYSTAL x2», «CRYSTAL 2») → requisiti di struttura.
     *
     * @return list<array{rank: string, count: int}>
     */
    public static function textToStructure(string $text): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', str_replace(';', ',', $text))), 'strlen') as $part) {
            if (! preg_match('/^([A-Za-z_]+)\s*[:x×*]?\s*(\d+)$/u', $part, $m) || (int) $m[2] < 1) {
                throw new \InvalidArgumentException("Requisito di struttura non valido: «{$part}» (scrivi ad esempio CRYSTAL:2, RUBY:2)");
            }
            $out[] = ['rank' => strtoupper($m[1]), 'count' => (int) $m[2]];
        }

        return $out;
    }

    /** Numeri come stringa o intero, accettando la virgola decimale. */
    private static function num(mixed $v): string|int
    {
        $s = str_replace(',', '.', trim((string) $v));
        if ($s === '' || ! is_numeric($s)) {
            throw new \InvalidArgumentException("Valore numerico non valido: «{$v}»");
        }

        return ctype_digit($s) ? (int) $s : $s;
    }
}
