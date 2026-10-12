<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abbonamento pagabile con carta o KMoney. Con Stripe la carta si salva al primo pagamento
        // (cliente + metodo di pagamento) e i cicli successivi si addebitano senza il cliente davanti.
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->string('payment_method', 10)->default('card')->after('country_code'); // card | kmoney
            $t->string('stripe_customer_id')->nullable()->after('payment_method');
            $t->string('stripe_payment_method_id')->nullable()->after('stripe_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropColumn(['payment_method', 'stripe_customer_id', 'stripe_payment_method_id']);
        });
    }
};
