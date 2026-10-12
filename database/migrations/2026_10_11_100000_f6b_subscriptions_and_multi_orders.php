<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abbonamento: un box (o più prodotti) ricorrente, un ordine al mese, pagato con carta.
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users');
            $t->string('status')->default('active'); // active | paused | cancelled
            $t->json('lines');                        // product_id => qty per chi sottoscrive
            $t->json('for_others')->nullable();       // recipient_user_id => [product_id => qty] (ordine multiplo)
            $t->string('country_code', 2)->default('IT');
            $t->json('billing');
            $t->unsignedSmallInteger('commitment_months')->default(0); // 0 = senza impegno
            $t->boolean('free_shipping')->default(false);              // fissata alla sottoscrizione (RF-A03)
            $t->dateTime('started_at');
            $t->dateTime('next_run_at')->nullable();
            $t->unsignedInteger('cycles_done')->default(0);
            $t->unsignedTinyInteger('retry_count')->default(0);
            $t->text('last_error')->nullable();
            $t->dateTime('paused_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->boolean('early_cancel')->default(false); // disdetta prima dell'impegno (OPEN-40)
            $t->timestamps();
            $t->index(['status', 'next_run_at']);
        });

        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('subscription_id')->nullable()->after('beneficiary_consultant_id')->constrained('subscriptions')->nullOnDelete();
            $t->boolean('shipping_waived')->default(false)->after('shipping_vat_rate');
        });

        // Ordine multiplo: ogni riga porta il proprio destinatario; PV e catena Team seguono il beneficiario della riga.
        Schema::table('order_items', function (Blueprint $t) {
            $t->foreignId('recipient_user_id')->nullable()->constrained('users');
            $t->foreignId('beneficiary_consultant_id')->nullable()->constrained('consultants');
            $t->string('pv_kind', 3)->nullable(); // pvf | pvc, fissato all'ordine
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('recipient_user_id');
            $t->dropConstrainedForeignId('beneficiary_consultant_id');
            $t->dropColumn('pv_kind');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('subscription_id');
            $t->dropColumn('shipping_waived');
        });
        Schema::dropIfExists('subscriptions');
    }
};
