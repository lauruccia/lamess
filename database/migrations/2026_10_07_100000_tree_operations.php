<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fase 3: operazioni sull'albero con data di efficacia, motivo e storico; riassegnazione clienti con storico. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sponsor_history', function (Blueprint $t) {
            $t->date('effective_from')->nullable();
            $t->boolean('with_branch')->default(true);
            $t->boolean('recalc_closed')->default(false);   // scelta: i mesi chiusi interessati vanno ricalcolati (riapertura in fase 6)
            $t->text('note')->nullable();                   // motivo dell'operazione
            $t->json('impact')->nullable();                 // anteprima: persone spostate, mesi chiusi interessati
        });
        Schema::create('customer_assignment_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->foreignId('from_consultant_id')->nullable()->constrained('consultants')->nullOnDelete();
            $t->foreignId('to_consultant_id')->nullable()->constrained('consultants')->nullOnDelete();
            $t->string('reason');
            $t->string('changed_by')->nullable();
            $t->timestamps();
            $t->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_assignment_history');
        Schema::table('sponsor_history', fn (Blueprint $t) => $t->dropColumn(['effective_from', 'with_branch', 'recalc_closed', 'note', 'impact']));
    }
};
