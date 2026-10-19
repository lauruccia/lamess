<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nazione dell'indirizzo di spedizione (ISO 2 lettere): decide tariffa di spedizione e prodotti vendibili.
        Schema::table('shipping_addresses', function (Blueprint $t) {
            $t->string('country_code', 2)->default('IT')->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('shipping_addresses', function (Blueprint $t) {
            $t->dropColumn('country_code');
        });
    }
};
