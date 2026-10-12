<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Richieste dell'amministrazione all'incaricato: «mandaci questi documenti» (KYC). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->json('types');                              // chiavi di config('lamess.kyc_document_types')
            $t->text('message')->nullable();
            $t->string('requested_by', 120);                // etichetta dell'admin (come nel log di audit)
            $t->dateTime('due_at')->nullable();
            $t->dateTime('last_reminder_at')->nullable();
            $t->unsignedSmallInteger('reminders_sent')->default(0);
            $t->dateTime('fulfilled_at')->nullable();
            $t->dateTime('canceled_at')->nullable();
            $t->timestamps();
            $t->index(['consultant_id', 'fulfilled_at', 'canceled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_requests');
    }
};
