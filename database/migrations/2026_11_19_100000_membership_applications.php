<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Candidature di chi chiede di entrare in Lamèss dalla pagina «Candidati»: l'account nasce solo dopo l'approvazione. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_applications', function (Blueprint $t): void {
            $t->id();
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('email', 190)->index();
            $t->string('phone', 40);
            $t->string('city', 100);
            $t->string('province', 4)->nullable();
            $t->string('interest', 20); // customer | consultant
            $t->text('message')->nullable();
            $t->timestamp('privacy_at');
            $t->string('status', 20)->default('pending')->index(); // pending | approved | rejected | registered
            $t->foreignId('sponsor_id')->nullable()->constrained('consultants')->nullOnDelete();
            $t->string('token', 64)->nullable()->unique();
            $t->timestamp('token_expires_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('email_sent_at')->nullable();
            $t->string('email_note', 255)->nullable();
            $t->foreignId('registered_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('registered_at')->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_applications');
    }
};
