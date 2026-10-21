<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tasca (ex wallet): accredito dei Bonus di Struttura e documento fiscale facoltativo sul prelievo (si può aggiungere dopo). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('structure_bonuses', function (Blueprint $t): void {
            $t->timestamp('wallet_credited_at')->nullable();
        });
        Schema::table('withdrawal_requests', function (Blueprint $t): void {
            $t->string('document_path')->nullable();
            $t->string('document_name')->nullable();
            $t->timestamp('document_added_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawal_requests', fn (Blueprint $t) => $t->dropColumn(['document_path', 'document_name', 'document_added_at']));
        Schema::table('structure_bonuses', fn (Blueprint $t) => $t->dropColumn('wallet_credited_at'));
    }
};
