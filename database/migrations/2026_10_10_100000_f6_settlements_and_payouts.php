<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un pagamento (bonifico) può coprire più liquidazioni: quelle del mese e i residui sotto soglia dei mesi precedenti.
        Schema::create('payouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained('consultants');
            $t->foreignId('period_id')->constrained('periods'); // mese per cui è stato preparato
            $t->decimal('amount', 15, 2);
            $t->string('iban')->nullable(); // copia al momento della preparazione
            $t->string('iban_holder')->nullable();
            $t->string('status')->default('to_pay'); // to_pay | paid | cancelled
            $t->string('method')->default('bonifico');
            $t->string('reference')->nullable(); // CRO / riferimento del bonifico
            $t->dateTime('paid_at')->nullable();
            $t->string('prepared_by')->nullable();
            $t->string('paid_by')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->dateTime('exported_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'consultant_id']);
        });

        // Importo maturato da un consulente in un periodo chiuso (somma delle righe bloccate del runtime ufficiale).
        Schema::create('settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('period_id')->constrained('periods');
            $t->foreignId('consultant_id')->constrained('consultants');
            $t->decimal('accrued', 15, 2)->default(0); // righe maturate (negative = recuperi)
            $t->decimal('held', 15, 2)->default(0); // righe trattenute dal motore (es. Rank Bonus di un inattivo)
            $t->unsignedInteger('lines_count')->default(0);
            $t->string('status')->default('open'); // open | paid
            $t->foreignId('payout_id')->nullable()->constrained('payouts')->nullOnDelete();
            $t->timestamps();
            $t->unique(['period_id', 'consultant_id']);
            $t->index(['consultant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('payouts');
    }
};
