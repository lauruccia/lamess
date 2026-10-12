<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\Period;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Verifica del motore: confronta qualifica e PR di carriera calcolati dal portale (statistiche del periodo)
 * con quelli esportati da Odoo (consulenti.csv: rank_odoo, pr_carriera_odoo). Le differenze finiscono nella riconciliazione.
 */
final class OdooVerification
{
    private const TOLERANCE = 0.01;

    /** @return array{period: string, compared: int, rank_equal: int, pr_equal: int, rank_diff: list<array<string,mixed>>, pr_diff: list<array<string,mixed>>} */
    public function compare(string $dir, string $periodCode, bool $dryRun = false): array
    {
        $period = Period::query()->where('code', $periodCode)->first() ?? throw new InvalidArgumentException("Periodo {$periodCode} inesistente.");
        $runId = $period->official_run_id ?? DB::table('calculation_runs')->where('period_id', $period->id)->where('status', 'completed')->orderByDesc('id')->value('id')
            ?? throw new InvalidArgumentException("Il periodo {$periodCode} non ha ancora un calcolo: lancia `lamess:periodo runtime {$periodCode}`.");

        $rows = $this->rows($dir.DIRECTORY_SEPARATOR.'consulenti.csv');
        $ours = DB::table('consultant_period_stats as s')->join('consultants as c', 'c.id', '=', 's.consultant_id')->where('s.run_id', $runId)
            ->whereNotNull('c.referral_code')->get(['c.referral_code', 'c.name', 's.achieved_rank', 's.pr_closing', 's.group_pr_closing'])->keyBy('referral_code');

        $bench = [];
        $kids = [];
        foreach ($rows as $r) {
            if ($r['ref'] !== '') {
                $bench[$r['ref']] = (float) $r['pr_carriera_odoo'];
                if ($r['sponsor_ref'] !== '') {
                    $kids[$r['sponsor_ref']][] = $r['ref'];
                }
            }
        }
        $out = ['period' => $periodCode, 'compared' => 0, 'rank_equal' => 0, 'pr_equal' => 0, 'rank_diff' => [], 'pr_diff' => []];
        foreach ($rows as $r) {
            $o = $ours[$r['ref']] ?? null;
            if ($o === null) {
                continue;
            }
            $out['compared']++;
            $odooRank = strtoupper($r['rank_odoo'] !== '' ? $r['rank_odoo'] : 'CONSULTANT');
            if ($odooRank === $o->achieved_rank) {
                $out['rank_equal']++;
            } else {
                $out['rank_diff'][] = ['ref' => $r['ref'], 'name' => $r['nome'], 'odoo' => $odooRank, 'portale' => $o->achieved_rank, 'pr_odoo' => (float) $r['pr_carriera_odoo'], 'pr_portale' => (float) $o->group_pr_closing];
            }
            $group = (float) $o->group_pr_closing;
            if (abs($group - (float) $r['pr_carriera_odoo']) < self::TOLERANCE) {
                $out['pr_equal']++;
            } else {
                $implied = (float) $r['pr_carriera_odoo'] - array_sum(array_map(fn ($k) => $bench[$k] ?? 0.0, $kids[$r['ref']] ?? []));
                $out['pr_diff'][] = [
                    'ref' => $r['ref'], 'name' => $r['nome'], 'pr_odoo' => (float) $r['pr_carriera_odoo'], 'pr_portale' => $group,
                    'personali_odoo_impliciti' => $implied, 'personali_portale' => (float) $o->pr_closing,
                ];
            }
        }
        if (! $dryRun) {
            $this->store($out);
        }

        return $out;
    }

    /**
     * Confronta i totali dei bonus del mese (Direct, Team, Matching, Rank, Fast Start) calcolati dal portale con quelli di Odoo
     * (`commission_period.csv`, dall'export «Commission Period»). Vale solo per il vecchio piano a PR: il Piano Incaricati ha altre regole.
     *
     * @return array{period: string, rows: list<array{type:string, odoo:float, portale:float, diff:float}>, total_odoo: float, total_portale: float}
     */
    public function compareBonuses(string $dir, string $periodCode, bool $dryRun = false): array
    {
        $period = Period::query()->where('code', $periodCode)->first() ?? throw new InvalidArgumentException("Periodo {$periodCode} inesistente.");
        $runId = $period->official_run_id ?? DB::table('calculation_runs')->where('period_id', $period->id)->where('status', 'completed')->orderByDesc('id')->value('id')
            ?? throw new InvalidArgumentException("Il periodo {$periodCode} non ha ancora un calcolo.");
        $odoo = null;
        foreach ($this->rows($dir.DIRECTORY_SEPARATOR.'commission_period.csv') as $r) {
            if ($r['periodo'] === $periodCode) {
                $odoo = $r;
            }
        }
        $odoo ?? throw new InvalidArgumentException("commission_period.csv non contiene il periodo {$periodCode}.");
        $ours = DB::table('commission_lines')->where('run_id', $runId)->whereIn('engine_status', ['accrued', 'held'])->groupBy('bonus_type')->selectRaw('bonus_type, sum(amount) as total')->pluck('total', 'bonus_type');
        $map = ['direct' => ['direct'], 'team' => ['team'], 'matching' => ['matching'], 'rank' => ['rank', 'rank_installment'], 'fast_start' => ['fsb']];
        $rows = [];
        foreach ($map as $col => $types) {
            $mine = round(array_sum(array_map(fn ($t) => (float) ($ours[$t] ?? 0), $types)), 2);
            $theirs = round((float) $odoo[$col], 2);
            $rows[] = ['type' => $col, 'odoo' => $theirs, 'portale' => $mine, 'diff' => round($mine - $theirs, 2)];
        }
        $out = ['period' => $periodCode, 'rows' => $rows, 'total_odoo' => round(array_sum(array_column($rows, 'odoo')), 2), 'total_portale' => round(array_sum(array_column($rows, 'portale')), 2)];
        if (! $dryRun) {
            $now = now();
            foreach ($rows as $d) {
                if (abs($d['diff']) < self::TOLERANCE) {
                    continue;
                }
                $row = ['severity' => 'warning', 'subject_name' => $d['type'], 'message' => "Bonus {$d['type']} {$periodCode}: Odoo {$d['odoo']} € ≠ portale {$d['portale']} €.", 'odoo' => json_encode($d), 'updated_at' => $now];
                $key = ['source' => 'odoo', 'code' => 'verifica_bonus_diverso', 'subject_ref' => "{$periodCode}:{$d['type']}"];
                if (DB::table('import_reconciliations')->where($key)->exists()) {
                    DB::table('import_reconciliations')->where($key)->update($row);
                } else {
                    DB::table('import_reconciliations')->insert($key + $row + ['status' => 'open', 'created_at' => $now]);
                }
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $out */
    private function store(array $out): void
    {
        $now = now();
        $items = [];
        foreach ($out['rank_diff'] as $d) {
            $items[] = ['verifica_rank_diverso', 'warning', $d['ref'], $d['name'], "Qualifica Odoo {$d['odoo']} ≠ portale {$d['portale']} (calcolo {$out['period']}).", $d];
        }
        foreach ($out['pr_diff'] as $d) {
            $items[] = ['verifica_pr_diverso', 'info', $d['ref'], $d['name'], 'PR di gruppo Odoo '.$d['pr_odoo'].' ≠ portale '.$d['pr_portale'].' (calcolo '.$out['period'].').', $d];
        }
        foreach ($items as [$code, $severity, $ref, $name, $msg, $data]) {
            $row = ['severity' => $severity, 'subject_name' => $name, 'message' => $msg, 'odoo' => json_encode($data), 'updated_at' => $now];
            $key = ['source' => 'odoo', 'code' => $code, 'subject_ref' => $ref];
            if (DB::table('import_reconciliations')->where($key)->exists()) {
                DB::table('import_reconciliations')->where($key)->update($row);
            } else {
                DB::table('import_reconciliations')->insert($key + $row + ['status' => 'open', 'created_at' => $now]);
            }
        }
    }

    /** @return list<array<string,string>> */
    private function rows(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File mancante: {$path}");
        }
        $h = fopen($path, 'rb');
        $head = fgetcsv($h, 0, ';', '"', '');
        $head[0] = ltrim((string) $head[0], "\xEF\xBB\xBF");
        $rows = [];
        while (($line = fgetcsv($h, 0, ';', '"', '')) !== false) {
            if ($line !== [null]) {
                $rows[] = array_combine($head, array_pad(array_slice($line, 0, count($head)), count($head), ''));
            }
        }
        fclose($h);

        return $rows;
    }
}
