<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cessione del codice/account (Reg. 9.1) e successione agli eredi (Reg. 9.2). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ivd_contracts', function (Blueprint $t) {
            $t->string('kind', 20)->default('new')->after('status'); // new | transfer | succession
        });
        Schema::create('account_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->nullable()->unique();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignor_user_id')->constrained('users');
            $t->foreignId('assignee_user_id')->constrained('users');
            $t->string('status', 20)->default('awaiting_payment');   // awaiting_payment | paid | approved | rejected | cancelled
            $t->decimal('fee_amount', 10, 2);
            $t->string('fee_method', 20)->nullable();
            $t->dateTime('fee_paid_at')->nullable();
            $t->string('fee_marked_by')->nullable();
            $t->dateTime('requested_at');
            $t->dateTime('decided_at')->nullable();
            $t->string('decided_by')->nullable();
            $t->text('decision_note')->nullable();
            $t->decimal('balance_at_decision', 15, 2)->nullable();
            $t->boolean('balance_follows_account')->nullable();
            $t->foreignId('new_contract_id')->nullable()->constrained('ivd_contracts')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'consultant_id']);
        });
        Schema::create('inheritance_cases', function (Blueprint $t) {
            $t->id();
            $t->string('number', 20)->nullable()->unique();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->date('death_date');
            $t->string('status', 20)->default('reported');           // reported | verified | liquidated | closed
            $t->string('outcome', 20)->nullable();                   // succession | termination
            $t->string('reported_by')->nullable();
            $t->dateTime('reported_at');
            $t->string('verified_by')->nullable();
            $t->dateTime('verified_at')->nullable();
            $t->decimal('estate_amount', 15, 2)->nullable();         // importo congelato alla liquidazione
            $t->dateTime('liquidated_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->index('status');
        });
        Schema::create('inheritance_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inheritance_case_id')->constrained()->cascadeOnDelete();
            $t->string('type', 30);                                  // death_certificate | will | court_order | other
            $t->string('path');
            $t->string('original_name');
            $t->string('status', 20)->default('pending');            // pending | approved | rejected
            $t->text('notes')->nullable();
            $t->string('uploaded_by')->nullable();
            $t->string('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('inheritance_heirs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('inheritance_case_id')->constrained()->cascadeOnDelete();
            $t->string('full_name');
            $t->string('tax_code', 16);
            $t->decimal('share_percent', 6, 3);
            $t->string('iban')->nullable();
            $t->string('iban_holder')->nullable();
            $t->decimal('amount', 15, 2)->nullable();
            $t->string('status', 20)->default('defined');            // defined | to_pay | paid
            $t->string('reference')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->string('paid_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inheritance_heirs');
        Schema::dropIfExists('inheritance_documents');
        Schema::dropIfExists('inheritance_cases');
        Schema::dropIfExists('account_transfers');
        Schema::table('ivd_contracts', fn (Blueprint $t) => $t->dropColumn('kind'));
    }
};
