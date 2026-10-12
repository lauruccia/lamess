<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultants', function (Blueprint $t) {
            // Ingresso effettivo in rete per il motore: precede la firma della lettera (since) per chi operava già in Odoo.
            $t->dateTime('network_since')->nullable()->after('since');
        });
    }

    public function down(): void
    {
        Schema::table('consultants', fn (Blueprint $t) => $t->dropColumn('network_since'));
    }
};
