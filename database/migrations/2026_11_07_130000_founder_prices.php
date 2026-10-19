<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Incaricati founder: comprano ai prezzi founder dei prodotti (solo il prezzo cambia, i punti restano quelli del prodotto).
        Schema::table('consultants', function (Blueprint $t) {
            $t->boolean('is_founder')->default(false);
        });
        Schema::table('products', function (Blueprint $t) {
            $t->decimal('founder_net_price', 15, 8)->nullable()->after('net_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropColumn('founder_net_price');
        });
        Schema::table('consultants', function (Blueprint $t) {
            $t->dropColumn('is_founder');
        });
    }
};
