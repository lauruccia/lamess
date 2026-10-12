<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mesi saltati dal cliente (es. vacanza): il calendario degli addebiti si sposta in avanti di altrettanti mesi.
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->unsignedSmallInteger('skip_months')->default(0)->after('cycles_done');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropColumn('skip_months');
        });
    }
};
