<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use App\Domain\Import\OdooHistoryImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class LamessImportaStorico extends Command
{
    protected $signature = 'lamess:importa-storico {cartella=database/data/odoo : cartella con ordini.csv, fatture.csv, movimenti.csv, clienti.csv, consulenti.csv} {--prova : non scrive nulla, mostra solo il riepilogo}';

    protected $description = 'Importa lo storico di Odoo (ordini, fatture, note di credito) e riempie la tabella di riconciliazione. Da lanciare dopo lamess:importa-odoo (nessuna email)';

    public function handle(OdooHistoryImporter $importer): int
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
            AuditLog::record('import.odoo_storico', 'Importazione dello storico Odoo eseguita', ['Import', 'odoo-storico'], ['conteggi' => $r['counts'], 'differenze' => $r['issues']]);
        }
        $this->info(($this->option('prova') ? '[PROVA — nessuna scrittura] ' : '').'Importazione storico completata');
        $this->table(['Voce', 'Numero'], collect($r['counts'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());
        $this->info('Differenze da riconciliare');
        $this->table(['Tipo', 'Numero'], collect($r['issues'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());

        return self::SUCCESS;
    }
}
