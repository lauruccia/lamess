<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Importo in euro (IVA inclusa) di ogni movimento: il piano Incaricati non usa più i PV. Vuoto = movimento importato senza importo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pv_movements', function (Blueprint $table): void {
            $table->decimal('amount', 15, 2)->nullable()->after('cv');
        });
    }

    public function down(): void
    {
        Schema::table('pv_movements', function (Blueprint $table): void {
            $table->dropColumn('amount');
        });
    }
};
