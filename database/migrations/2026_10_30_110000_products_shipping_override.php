<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Costo di spedizione specifico del prodotto (netto): vuoto = vale la tariffa generale; 0 = spedizione gratuita.
        Schema::table('products', fn (Blueprint $t) => $t->decimal('shipping_net', 15, 2)->nullable());
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('shipping_net'));
    }
};
