<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Compensi della Tasca usati per pagare ordini, con maggiorazione (es. +25%).
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('tasca_amount', 12, 2)->default(0);   // valore di listino coperto dalla Tasca (es. 150,00)
            $t->decimal('tasca_used', 12, 2)->default(0);     // compensi realmente usati (es. 120,00)
            $t->decimal('tasca_bonus', 12, 2)->default(0);    // maggiorazione concessa (es. 30,00): il costo dell'iniziativa
        });
        Schema::table('products', function (Blueprint $t) {
            $t->boolean('tasca_enabled')->default(true);      // il prodotto si può pagare con i compensi della Tasca
        });
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->boolean('use_tasca')->default(false);         // i rinnovi usano la Tasca finché c'è saldo, poi la carta
        });
        Schema::table('wallet_transactions', function (Blueprint $t) {
            $t->unsignedBigInteger('order_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', fn (Blueprint $t) => $t->dropColumn('order_id'));
        Schema::table('subscriptions', fn (Blueprint $t) => $t->dropColumn('use_tasca'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('tasca_enabled'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['tasca_amount', 'tasca_used', 'tasca_bonus']));
    }
};
