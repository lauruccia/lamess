<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rifiuto dei documenti KYC: motivo predefinito e promemoria a chi non ricarica. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_documents', function (Blueprint $t) {
            $t->string('reject_reason', 40)->nullable();          // chiave di config('lamess.kyc_reject_reasons')
            $t->unsignedSmallInteger('reminders_sent')->default(0);
            $t->dateTime('last_reminder_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('kyc_documents', function (Blueprint $t) {
            $t->dropColumn(['reject_reason', 'reminders_sent', 'last_reminder_at']);
        });
    }
};
