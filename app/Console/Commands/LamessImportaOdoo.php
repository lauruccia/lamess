<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use App\Domain\Import\OdooImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class LamessImportaOdoo extends Command
{
    protected $signature = 'lamess:importa-odoo {cartella=database/data/odoo : cartella con consulenti.csv, clienti.csv, movimenti.csv} {--prova : non scrive nulla, mostra solo il riepilogo}';

    protected $description = 'Importa incaricati, albero sponsor, clienti e movimenti PV dai CSV puliti prodotti da database/import/odoo_etl.py (nessuna email)';

    public function handle(OdooImporter $importer): int
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
            AuditLog::record('import.odoo', 'Importazione da Odoo eseguita', ['Import', 'odoo'], ['conteggi' => $r['counts']]);
        }
        $this->info(($this->option('prova') ? '[PROVA — nessuna scrittura] ' : '').'Importazione completata');
        $this->table(['Voce', 'Numero'], collect($r['counts'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());
        foreach ($r['notes'] as $note) {
            $this->warn($note);
        }

        return self::SUCCESS;
    }
}
