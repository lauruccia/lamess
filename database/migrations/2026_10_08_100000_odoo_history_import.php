<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $t->string('source')->nullable()->after('number'); // null = nato nel portale; 'odoo' = storico importato
        });
        Schema::table('invoices', function (Blueprint $t) {
            $t->string('source')->nullable()->after('number');
        });
        Schema::table('credit_notes', function (Blueprint $t) {
            $t->string('source')->nullable()->after('number');
        });
        // una fattura storica può non avere un ordine riconoscibile: resta senza ordine e finisce in riconciliazione
        Schema::table('invoices', function (Blueprint $t) {
            $t->foreignId('order_id')->nullable()->change();
        });
        Schema::table('credit_notes', function (Blueprint $t) {
            $t->foreignId('order_id')->nullable()->change();
        });

        Schema::create('import_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->string('source')->default('odoo');
            $t->string('code');                          // es. fattura_importo_diverso
            $t->string('severity');                      // error | warning | info
            $t->string('subject_ref');                   // es. K00443, FT/2026/00012
            $t->string('subject_name')->nullable();
            $t->text('message');
            $t->json('odoo')->nullable();
            $t->json('portal')->nullable();
            $t->string('status')->default('open');       // open | accepted
            $t->text('resolution_note')->nullable();
            $t->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('resolved_at')->nullable();
            $t->timestamps();
            $t->unique(['source', 'code', 'subject_ref']);
            $t->index(['status', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_reconciliations');
        Schema::table('credit_notes', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('source'));
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn('source'));
    }
};
