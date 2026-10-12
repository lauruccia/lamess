<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Data di efficacia delle versioni del piano (fase 2): una versione attiva vale dal giorno indicato. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comp_plan_versions', function (Blueprint $t) {
            $t->date('effective_from')->nullable()->after('activated_at');
        });
        // Le versioni già attive restano senza data: valgono da sempre (nessuna restrizione sui periodi storici).
    }

    public function down(): void
    {
        Schema::table('comp_plan_versions', fn (Blueprint $t) => $t->dropColumn('effective_from'));
    }
};
