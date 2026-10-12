<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            // Qualifica del sistema precedente (piano Consulenti), congelata per il confronto con il piano Incaricati.
            $table->string('previous_rank_code')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            $table->dropColumn('previous_rank_code');
        });
    }
};
