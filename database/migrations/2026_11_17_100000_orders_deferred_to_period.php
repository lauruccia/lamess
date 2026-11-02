<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Controllo dei bonifici in sospeso a fine mese: l'admin può rimandare un ordine in attesa al mese successivo.
 * Finché il mese indicato non arriva, la chiusura del mese precedente non lo annulla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->string('deferred_to_period', 7)->nullable()->index();   // es. 2026-11
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t): void {
            $t->dropIndex(['deferred_to_period']);
            $t->dropColumn('deferred_to_period');
        });
    }
};
