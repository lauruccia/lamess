<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assegnazione libera dei punti: il cliente a cui si riferisce un movimento senza ordine (la persona che il piano conta
 * come cliente dell'incaricato per i mesi di validità). La causale sta nella colonna «note» già esistente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pv_movements', function (Blueprint $t): void {
            $t->foreignId('client_user_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pv_movements', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('client_user_id');
        });
    }
};
