<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Netto della spedizione a 8 decimali, come già i prezzi dei prodotti (price_precision).
 * La spedizione si decide IVA inclusa (es. 13,00 €): con il netto a 2 decimali (10,66) l'IVA arrotondata dava 13,01,
 * e non esiste alcun netto a 2 decimali che dia esattamente 13,00. Con il netto esatto (10,6557377) il totale torna 13,00.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipping_rates', fn (Blueprint $t) => $t->decimal('amount_net', 15, 8)->change());
        Schema::table('products', fn (Blueprint $t) => $t->decimal('shipping_net', 15, 8)->nullable()->change());
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('shipping_net', 15, 8)->default(0)->change();
            $t->decimal('shipping_refunded_net', 15, 8)->default(0)->change();
            $t->decimal('shipping_discount_net', 15, 8)->default(0)->change();
        });
        Schema::table('credit_notes', fn (Blueprint $t) => $t->decimal('shipping_net', 15, 8)->default(0)->change());
    }

    public function down(): void
    {
        Schema::table('shipping_rates', fn (Blueprint $t) => $t->decimal('amount_net', 15, 2)->change());
        Schema::table('products', fn (Blueprint $t) => $t->decimal('shipping_net', 15, 2)->nullable()->change());
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('shipping_net', 15, 2)->default(0)->change();
            $t->decimal('shipping_refunded_net', 15, 2)->default(0)->change();
            $t->decimal('shipping_discount_net', 15, 2)->default(0)->change();
        });
        Schema::table('credit_notes', fn (Blueprint $t) => $t->decimal('shipping_net', 15, 2)->default(0)->change());
    }
};
