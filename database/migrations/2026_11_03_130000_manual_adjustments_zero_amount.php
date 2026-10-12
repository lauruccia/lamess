<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Le rettifiche manuali importate da Odoo sono punti di carriera, non vendite: importo 0 invece della conversione in euro. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('pv_movements')->where('type', 'migration')->whereNull('amount')->where('note', 'like', 'Rettifica manuale%')->update(['amount' => '0.00']);
    }

    public function down(): void
    {
        DB::table('pv_movements')->where('type', 'migration')->where('amount', 0)->where('note', 'like', 'Rettifica manuale%')->update(['amount' => null]);
    }
};
