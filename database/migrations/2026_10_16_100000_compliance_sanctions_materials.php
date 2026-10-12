<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Conformità: casi, sanzioni con effetti automatici e materiali promozionali approvati. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_cases', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->nullable()->unique();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category', 30);
            $t->text('description');
            $t->string('status', 20)->default('open');       // open | investigating | sanctioned | dismissed
            $t->string('opened_by')->nullable();
            $t->dateTime('opened_at');
            $t->string('decided_by')->nullable();
            $t->text('decision_note')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'consultant_id']);
        });
        Schema::create('sanctions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('compliance_case_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20);                          // warning | commission_hold | suspension | termination
            $t->text('reason');
            $t->dateTime('starts_at');
            $t->dateTime('ends_at')->nullable();             // vuoto = fino a revoca
            $t->string('status', 20)->default('active');     // active | lifted | expired
            $t->string('applied_by')->nullable();
            $t->boolean('had_hold_before')->default(false);  // il blocco compensi c'era già prima di questa sanzione
            $t->dateTime('lifted_at')->nullable();
            $t->string('lifted_by')->nullable();
            $t->text('lift_reason')->nullable();
            $t->timestamps();
            $t->index(['status', 'ends_at']);
        });
        Schema::create('promo_materials', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->nullable()->unique();    // MA-2026-0001: il consulente lo cita quando usa il materiale
            $t->foreignId('submitted_by_consultant_id')->nullable()->constrained('consultants')->nullOnDelete(); // vuoto = materiale dell'azienda
            $t->string('title');
            $t->text('description')->nullable();
            $t->text('claims')->nullable();                  // testo o affermazioni da approvare
            $t->string('file_path')->nullable();
            $t->string('original_name')->nullable();
            $t->string('url')->nullable();
            $t->string('status', 20)->default('pending');    // pending | approved | rejected | withdrawn
            $t->boolean('shared')->default(false);           // approvato e messo a disposizione di tutta la rete
            $t->string('decided_by')->nullable();
            $t->dateTime('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->date('valid_until')->nullable();
            $t->timestamps();
            $t->index(['status', 'shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_materials');
        Schema::dropIfExists('sanctions');
        Schema::dropIfExists('compliance_cases');
    }
};
