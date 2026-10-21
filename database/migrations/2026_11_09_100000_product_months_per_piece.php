<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Interruttore per prodotto: i pezzi di una riga d'ordine moltiplicano i mesi di validità del cliente (acceso di default). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->boolean('months_per_piece')->default(true));
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('months_per_piece'));
    }
};
