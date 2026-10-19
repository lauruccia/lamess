<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Codice a barre (EAN-13 o EAN-8) del prodotto o della variante.
        Schema::table('products', function (Blueprint $t) {
            $t->string('barcode', 14)->nullable()->after('sku');
            $t->index('barcode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->dropIndex(['barcode']);
            $t->dropColumn('barcode');
        });
    }
};
