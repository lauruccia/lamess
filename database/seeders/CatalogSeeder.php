<?php

namespace Database\Seeders;

use App\Domain\Catalog\CatalogImporter;
use Illuminate\Database\Seeder;

/** Catalogo reale (listino Odoo, prezzi IVA inclusa, PV dalla colonna «PR numerico»). Idempotente. */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        app(CatalogImporter::class)->import(database_path('data/catalogo-odoo.csv'));
    }
}
