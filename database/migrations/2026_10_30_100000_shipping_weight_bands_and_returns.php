<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Peso del prodotto (grammi) per le tariffe a fascia di peso; vuoto = non conta.
        Schema::table('products', fn (Blueprint $t) => $t->unsignedInteger('weight_g')->nullable());

        // Più tariffe per paese (fasce di peso); «*» = resto del mondo. Il paese non è più univoco.
        Schema::table('shipping_rates', function (Blueprint $t) {
            $t->dropUnique(['country_code']);
            $t->string('name', 80)->nullable();
            $t->unsignedInteger('min_weight_g')->default(0);
            $t->unsignedInteger('max_weight_g')->nullable();
            $t->index('country_code');
        });

        // Spedizione rimborsata con un reso e richieste di reso del cliente.
        Schema::table('credit_notes', fn (Blueprint $t) => $t->decimal('shipping_net', 15, 2)->default(0));
        Schema::table('orders', fn (Blueprint $t) => $t->decimal('shipping_refunded_net', 15, 2)->default(0));
        Schema::create('return_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->string('status', 16)->default('requested');   // requested | approved | rejected
            $t->json('items');                                  // order_item_id => quantità
            $t->text('reason');
            $t->text('admin_note')->nullable();
            $t->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_requests');
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('shipping_refunded_net'));
        Schema::table('credit_notes', fn (Blueprint $t) => $t->dropColumn('shipping_net'));
        Schema::table('shipping_rates', function (Blueprint $t) {
            $t->dropIndex(['country_code']);
            $t->dropColumn(['name', 'min_weight_g', 'max_weight_g']);
            $t->unique('country_code');
        });
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('weight_g'));
    }
};
