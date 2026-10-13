<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Piano Incaricati: per il singolo incaricato l'admin può mantenere la qualifica attuale fino a una data scelta. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultants', function (Blueprint $t) {
            $t->date('rank_hold_until')->nullable();       // ultimo giorno di protezione della qualifica (i mesi che finiscono entro questa data sono protetti)
            $t->string('rank_hold_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('consultants', fn (Blueprint $t) => $t->dropColumn(['rank_hold_until', 'rank_hold_reason']));
    }
};
