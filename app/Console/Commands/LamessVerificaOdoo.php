<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\OdooVerification;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class LamessVerificaOdoo extends Command
{
    protected $signature = 'lamess:verifica-odoo {periodo=2026-09 : mese di confronto (calcolato e chiuso)} {cartella=database/data/odoo} {--bonus : confronta i totali dei bonus del mese (commission_period.csv) invece di qualifica e PR} {--prova : non scrive le differenze nella riconciliazione}';

    protected $description = 'Confronta qualifica e PR di carriera del motore con quelli esportati da Odoo (fase 5)';

    public function handle(OdooVerification $verification): int
    {
        $dir = (string) $this->argument('cartella');
        $absolute = str_starts_with($dir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir);
        if ($this->option('bonus')) {
            return $this->bonus($verification, $dir, $absolute);
        }
        try {
            $r = $verification->compare($absolute ? $dir : base_path($dir), (string) $this->argument('periodo'), (bool) $this->option('prova'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Confronto con Odoo sul periodo {$r['period']}: {$r['compared']} incaricati");
        $this->line("Qualifiche uguali: {$r['rank_equal']} / {$r['compared']}");
        $this->line("PR di gruppo uguali: {$r['pr_equal']} / {$r['compared']}");
        if ($r['rank_diff'] !== []) {
            $this->warn('Qualifiche diverse');
            $this->table(['Incaricato', 'Odoo', 'Portale', 'PR Odoo', 'PR portale'], array_map(fn ($d) => [$d['name'], $d['odoo'], $d['portale'], $d['pr_odoo'], $d['pr_portale']], $r['rank_diff']));
        }
        if ($r['pr_diff'] !== []) {
            $this->warn('PR diversi (personali impliciti = PR Odoo meno quelli dei diretti)');
            $this->table(['Incaricato', 'PR Odoo', 'PR portale', 'Personali Odoo', 'Personali portale'], array_map(fn ($d) => [$d['name'], $d['pr_odoo'], $d['pr_portale'], $d['personali_odoo_impliciti'], $d['personali_portale']], $r['pr_diff']));
        }

        return self::SUCCESS;
    }

    private function bonus(OdooVerification $verification, string $dir, bool $absolute): int
    {
        try {
            $r = $verification->compareBonuses($absolute ? $dir : base_path($dir), (string) $this->argument('periodo'), (bool) $this->option('prova'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Totali dei bonus sul periodo {$r['period']} (vecchio piano a PR)");
        $this->table(['Bonus', 'Odoo €', 'Portale €', 'Differenza €'], array_map(fn ($d) => [$d['type'], $d['odoo'], $d['portale'], $d['diff']], $r['rows']));
        $this->line("Totale Odoo {$r['total_odoo']} € · portale {$r['total_portale']} €");

        return self::SUCCESS;
    }
}
