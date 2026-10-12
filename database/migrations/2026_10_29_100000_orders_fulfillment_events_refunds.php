<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Evasione: corriere, «in preparazione» e «consegnato» (gli stati restano nella colonna status).
        Schema::table('orders', function (Blueprint $t) {
            $t->string('carrier', 60)->nullable()->after('tracking_code');
            $t->timestamp('processing_at')->nullable()->after('paid_at');
            $t->timestamp('delivered_at')->nullable()->after('shipped_at');
        });

        // Cronologia dell'ordine (come la timeline di Shopify): chi ha fatto cosa e quando, note interne comprese.
        Schema::create('order_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->string('type', 40);
            $t->text('message');
            $t->boolean('internal')->default(false);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->json('meta')->nullable();
            $t->timestamps();
            $t->index(['order_id', 'id']);
        });

        // Rimborsi in denaro: uno per ogni metodo con cui l'ordine era stato pagato.
        Schema::create('refunds', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('credit_note_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 15, 2);
            $t->string('method', 20);   // card | kmoney | transfer
            $t->string('status', 20)->default('pending'); // pending | done | failed
            $t->string('provider_ref')->nullable();
            $t->text('error')->nullable();
            $t->string('reason')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('refunded_at')->nullable();
            $t->timestamps();
            $t->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('order_events');
        Schema::table('orders', function (Blueprint $t) {
            $t->dropColumn(['carrier', 'processing_at', 'delivered_at']);
        });
    }
};
