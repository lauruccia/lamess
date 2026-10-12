<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditLog;
use App\Domain\Import\OdooAnagraficaImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class LamessImportaAnagrafica extends Command
{
    protected $signature = 'lamess:importa-anagrafica {cartella=database/data/odoo : cartella con anagrafica_odoo.csv} {--prova : non scrive nulla, mostra solo il riepilogo}';

    protected $description = 'Registra le firme storiche delle lettere, allinea il KYC e segnala le differenze con Odoo (nessuna email). Da lanciare dopo lamess:importa-odoo';

    public function handle(OdooAnagraficaImporter $importer): int
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
            AuditLog::record('import.odoo_anagrafica', 'Importazione dell’anagrafica (firme e KYC) di Odoo eseguita', ['Import', 'odoo-anagrafica'], ['conteggi' => $r['counts'], 'differenze' => $r['issues']]);
        }
        $this->info(($this->option('prova') ? '[PROVA — nessuna scrittura] ' : '').'Importazione anagrafica completata');
        $this->table(['Voce', 'Numero'], collect($r['counts'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());
        $this->info('Differenze da riconciliare');
        $this->table(['Tipo', 'Numero'], collect($r['issues'])->map(fn ($n, $k) => [str_replace('_', ' ', $k), $n])->values()->all());

        return self::SUCCESS;
    }
}
