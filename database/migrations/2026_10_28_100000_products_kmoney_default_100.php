<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Decisione del 08/10: tutti i prodotti sono pagabili al 100% in KMoney, salvo diversa impostazione in anagrafica.
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedTinyInteger('kmoney_percent')->default(100)->change();
        });
        DB::table('products')->update(['kmoney_percent' => 100]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->unsignedTinyInteger('kmoney_percent')->default(0)->change();
        });
    }
};
