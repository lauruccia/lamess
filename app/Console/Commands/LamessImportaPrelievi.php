<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use App\Domain\Import\OdooPayoutHistoryImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class LamessImportaPrelievi extends Command
{
    protected $signature = 'lamess:importa-prelievi {cartella=database/data/odoo : cartella con prelievi.csv} {--prova : non scrive nulla, mostra solo il riepilogo}';

    protected $description = 'Importa i prelievi, i pagamenti e le ricevute storiche di Odoo (nessuna email, il wallet non viene toccato). Da lanciare dopo lamess:importa-odoo';

    public function handle(OdooPayoutHistoryImporter $importer): int
    {
        $dir = (string) $this->argument('cartella');
        $absolute = str_starts_with($dir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir);
        try {
            $r = $importer->import($absolute ? $dir : base_path($dir), (bool) $this->option('prova'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (! $this->option('prova')) {
            AuditLog::record('import.odoo_prelievi', 'Importazione dei prelievi storici di Odoo eseguita', ['Import', 'odoo-prelievi'], ['conteggi' => $r['counts'], 'differenze' => $r['issues']]);
        }
        $this->info(($this->option('prova') ? '[PROVA — nessuna scrittura] ' : '').'Importazione prelievi completata');
        $this->table(['Voce', 'Numero'], collect($r['counts'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());
        $this->info('Differenze da riconciliare');
        $this->table(['Tipo', 'Numero'], collect($r['issues'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());

        return self::SUCCESS;
    }
}
