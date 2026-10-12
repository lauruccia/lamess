<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            // Quota del prezzo che il cliente può pagare in KMoney: 0 (non accettato), 25, 50, 75 o 100. Di default 100, modificabile per prodotto.
            $t->unsignedTinyInteger('kmoney_percent')->default(100);
        });

        Schema::table('orders', function (Blueprint $t) {
            // Parte dell'ordine (IVA inclusa) addebitata in KMoney e metodo scelto per il resto.
            $t->decimal('kmoney_amount', 12, 2)->default(0);
            $t->string('kmoney_rest_method', 16)->nullable();
        });

        Schema::create('kmoney_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->text('access_token');
            $t->text('refresh_token')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->string('mandate_uuid', 64)->nullable();
            $t->string('account_number', 32)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kmoney_links');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['kmoney_amount', 'kmoney_rest_method']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('kmoney_percent'));
    }
};
