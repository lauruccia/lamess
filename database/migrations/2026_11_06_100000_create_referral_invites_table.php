<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inviti referral: ogni invito ha un link personale (token) che lega click e registrazione a una persona precisa.
 * Stati: created (link creato), sent (inviato), opened (link aperto), registered (iscritto), expired (scaduto), failed (email non partita).
 * «Ha ordinato» non si salva: si ricava dagli ordini pagati dell'utente registrato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_invites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->string('token', 40)->unique();
            $t->string('recipient_name')->nullable();
            $t->string('email')->nullable()->index();
            $t->string('phone', 30)->nullable();
            $t->string('channel', 12)->default('email'); // email | whatsapp | link
            $t->string('status', 12)->default('created')->index();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->unsignedSmallInteger('reminders_count')->default(0);
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('last_opened_at')->nullable();
            $t->unsignedInteger('opens_count')->default(0);
            $t->timestamp('registered_at')->nullable();
            $t->foreignId('registered_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('expires_at')->nullable();
            $t->string('failure_reason')->nullable();
            $t->timestamps();
            $t->index(['consultant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_invites');
    }
};
