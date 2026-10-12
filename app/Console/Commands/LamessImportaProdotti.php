<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\CatalogImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class LamessImportaProdotti extends Command
{
    protected $signature = 'lamess:importa-prodotti {file=database/data/catalogo-odoo.csv} {--netti : i prezzi del file sono già al netto IVA} {--iva=22} {--prova : non scrive nulla, mostra solo il riepilogo}';

    protected $description = 'Importa o aggiorna il catalogo prodotti da CSV (sku;nome;prezzo_ivato;pv;attivo;note)';

    public function handle(CatalogImporter $importer): int
    {
        try {
            $file = (string) $this->argument('file');
            $absolute = str_starts_with($file, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $file);
            $r = $importer->import($absolute ? $file : base_path($file), ! $this->option('netti'), (string) $this->option('iva'), (bool) $this->option('prova'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(($this->option('prova') ? '[PROVA] ' : '')."Righe lette: {$r['rows']} — nuovi: {$r['created']}, aggiornati: {$r['updated']}");

        return self::SUCCESS;
    }
}
