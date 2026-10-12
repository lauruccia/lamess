<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Wallet provvigionale, prelievi su richiesta, ricevute provvigionali con ritenuta e INPS (parametri da validare col commercialista). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultants', function (Blueprint $t) {
            $t->string('fiscal_regime', 20)->default('occasional'); // occasional (ritenuta) | vat_holder (fattura propria)
        });
        Schema::table('payouts', function (Blueprint $t) {
            $t->decimal('gross_amount', 15, 2)->nullable();   // lordo; null = pagamento storico senza trattenute (amount = lordo)
            $t->decimal('withholding_amount', 15, 2)->default(0);
            $t->decimal('inps_amount', 15, 2)->default(0);    // quota INPS a carico del consulente
            $t->decimal('fee_amount', 15, 2)->default(0);     // commissione bonifico
            $t->unsignedSmallInteger('fiscal_year')->nullable();
        });
        Schema::table('settlements', function (Blueprint $t) {
            $t->dateTime('wallet_credited_at')->nullable();
        });
        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30);                            // commission_credit | clawback | withdrawal | transfer_fee | withholding_tax | inps | manual_adjustment
            $t->decimal('amount', 15, 2);                      // con segno: + accredito, − addebito
            $t->decimal('balance_after', 15, 2);
            $t->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('settlement_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedBigInteger('payout_id')->nullable();
            $t->unsignedBigInteger('withdrawal_request_id')->nullable();
            $t->string('description');
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->index(['consultant_id', 'id']);
        });
        Schema::create('withdrawal_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->decimal('gross', 15, 2);
            $t->decimal('withholding', 15, 2)->default(0);
            $t->decimal('inps', 15, 2)->default(0);
            $t->decimal('fee', 15, 2)->default(0);
            $t->decimal('net', 15, 2);
            $t->string('iban');
            $t->string('iban_holder')->nullable();
            $t->string('status', 20)->default('requested');    // requested | approved | paid | rejected | cancelled
            $t->dateTime('requested_at');
            $t->date('due_by');                                // entro 5 giorni lavorativi dalla richiesta
            $t->string('reviewed_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->text('rejection_reason')->nullable();
            $t->foreignId('payout_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'consultant_id']);
        });
        Schema::create('commission_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('number', 30)->unique();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payout_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('regime', 20);
            $t->dateTime('issued_at');
            $t->decimal('gross', 15, 2);
            $t->decimal('taxable_base', 15, 2)->default(0);
            $t->decimal('withholding', 15, 2)->default(0);
            $t->decimal('inps_base', 15, 2)->default(0);
            $t->decimal('inps_total', 15, 2)->default(0);
            $t->decimal('inps_worker', 15, 2)->default(0);
            $t->decimal('fee', 15, 2)->default(0);
            $t->decimal('net', 15, 2);
            $t->json('params')->nullable();                    // parametri fiscali usati: la ricevuta resta riproducibile
            $t->dateTime('dispute_deadline_at');
            $t->string('status', 20)->default('published');    // published | disputed | accepted | resolved
            $t->dateTime('disputed_at')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $t->string('outcome', 20)->nullable();
            $t->text('outcome_note')->nullable();
            $t->dateTime('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['consultant_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_receipts');
        Schema::dropIfExists('withdrawal_requests');
        Schema::dropIfExists('wallet_transactions');
        Schema::table('settlements', fn (Blueprint $t) => $t->dropColumn('wallet_credited_at'));
        Schema::table('payouts', fn (Blueprint $t) => $t->dropColumn(['gross_amount', 'withholding_amount', 'inps_amount', 'fee_amount', 'fiscal_year']));
        Schema::table('consultants', fn (Blueprint $t) => $t->dropColumn('fiscal_regime'));
    }
};
