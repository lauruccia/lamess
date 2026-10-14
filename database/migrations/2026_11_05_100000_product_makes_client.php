<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Rende cliente»: un prodotto che conta come cliente a prescindere dall'importo minimo del piano.
 * Sul prodotto; congelato su riga d'ordine e movimento. Spento di default: il comportamento resta quello della soglia in euro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->boolean('makes_client')->default(false));
        Schema::table('order_items', fn (Blueprint $t) => $t->boolean('makes_client')->default(false));
        Schema::table('pv_movements', fn (Blueprint $t) => $t->boolean('makes_client')->default(false));
    }

    public function down(): void
    {
        Schema::table('pv_movements', fn (Blueprint $t) => $t->dropColumn('makes_client'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('makes_client'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('makes_client'));
    }
};
