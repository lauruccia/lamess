<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Il pagamento settimanale del Bonus di Struttura passa dal pagamento (Payout) normale: ricevuta, ritenuta e INPS inclusi. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('structure_bonuses', function (Blueprint $table): void {
            $table->foreignId('payout_id')->nullable()->after('paid_by')->constrained('payouts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('structure_bonuses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('payout_id');
        });
    }
};
