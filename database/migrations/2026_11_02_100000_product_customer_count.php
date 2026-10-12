<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Quanti clienti vale l'acquisto di un prodotto per l'incaricato (vuoto = 1): sul prodotto, congelato su riga d'ordine e movimento. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->unsignedSmallInteger('customer_count')->nullable());
        Schema::table('order_items', fn (Blueprint $t) => $t->unsignedSmallInteger('client_count')->nullable());
        Schema::table('pv_movements', fn (Blueprint $t) => $t->unsignedSmallInteger('client_count')->nullable());
    }

    public function down(): void
    {
        Schema::table('pv_movements', fn (Blueprint $t) => $t->dropColumn('client_count'));
        Schema::table('order_items', fn (Blueprint $t) => $t->dropColumn('client_count'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('customer_count'));
    }
};
