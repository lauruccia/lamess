<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Spedizione gratuita per prodotto: una scelta per l'Italia e una per l'estero (spento = si paga la tariffa generale).
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('free_shipping_it')->default(false);
            $t->boolean('free_shipping_abroad')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['free_shipping_it', 'free_shipping_abroad']));
    }
};
