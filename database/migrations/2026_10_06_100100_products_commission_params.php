<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Parametri commissionali per prodotto (fase 2). I default riproducono il comportamento precedente. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->string('commission_base', 8)->default('net');             // net | gross: su cosa si calcolano i PV automatici
            $t->decimal('commissionable_pct', 5, 2)->default(100);        // quota del prezzo che genera PV automatici
            $t->string('pv_discount_mode', 12)->default('inherit');       // inherit (politica D02) | proportional | fixed
            $t->boolean('shipping_commissionable')->default(false);       // la spedizione di questo prodotto genera PV (oggi mai: OPEN-18)
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['commission_base', 'commissionable_pct', 'pv_discount_mode', 'shipping_commissionable']));
    }
};
