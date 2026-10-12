<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Assistenza (ticket) ed estratti conto provvigionali con termine di 10 giorni per i reclami (Reg. 5.2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->nullable()->unique();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('consultant_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category', 30);                       // general | technical | statement_dispute | receipt_dispute | report | other
            $t->string('subject');
            $t->string('status', 20)->default('open');        // open | answered | closed
            $t->string('assigned_to')->nullable();
            $t->nullableMorphs('related');
            $t->dateTime('last_message_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'category']);
        });
        Schema::create('ticket_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_staff')->default(false);
            $t->boolean('is_internal')->default(false);       // nota interna: il richiedente non la vede
            $t->text('body');
            $t->timestamps();
        });
        Schema::create('commission_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('period_id')->constrained()->cascadeOnDelete();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->dateTime('published_at');
            $t->dateTime('dispute_deadline_at');
            $t->string('status', 20)->default('published');   // published | disputed | accepted | resolved
            $t->dateTime('accepted_at')->nullable();
            $t->dateTime('disputed_at')->nullable();
            $t->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $t->string('outcome', 20)->nullable();            // upheld | rejected
            $t->text('outcome_note')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['period_id', 'consultant_id']);
            $t->index(['status', 'dispute_deadline_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_statements');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
