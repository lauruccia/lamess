<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ogni quanti mesi parte un ordine: 1 = mensile (come finora), 2 = bimestrale.
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->unsignedTinyInteger('interval_months')->default(1)->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropColumn('interval_months');
        });
    }
};
