<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Scadenza (next_run_at) per cui il promemoria pre-addebito è già partito: impedisce l'invio doppio.
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dateTime('reminded_for')->nullable()->after('next_run_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $t) {
            $t->dropColumn('reminded_for');
        });
    }
};
