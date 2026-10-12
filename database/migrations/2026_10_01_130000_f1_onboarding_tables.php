<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('type')->default('private'); // private | company
            $t->string('first_name')->nullable();
            $t->string('last_name')->nullable();
            $t->string('company_name')->nullable();
            $t->string('tax_code')->nullable()->index();
            $t->string('vat_number')->nullable();
            $t->string('sdi_code')->nullable();
            $t->string('pec')->nullable();
            $t->date('birth_date')->nullable();
            $t->string('birth_place')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->string('city')->nullable();
            $t->string('zip', 10)->nullable();
            $t->string('province', 3)->nullable();
            $t->string('country_code', 2)->default('IT');
            $t->timestamps();
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_consultant_id')->nullable()->constrained('consultants')->nullOnDelete();
            $t->string('source')->default('manual'); // referral | cookie | form_code | default | manual
            $t->dateTime('registered_at');
            $t->timestamps();
        });
        Schema::table('consultants', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $t->string('kyc_status')->default('none'); // none | submitted | verified | rejected
            $t->string('iban')->nullable();
            $t->string('iban_holder')->nullable();
            $t->dateTime('onboarding_completed_at')->nullable();
            $t->string('badge_number')->nullable();
            $t->date('badge_valid_until')->nullable();
            $t->string('badge_path')->nullable();
        });
        Schema::create('ivd_contracts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained();
            $t->foreignId('consultant_id')->nullable()->constrained('consultants');
            $t->foreignId('sponsor_id')->nullable()->constrained('consultants');
            $t->string('template_version');
            $t->string('status')->default('awaiting_signature'); // awaiting_signature | signed | accepted | rejected | expired
            $t->string('signature_provider')->nullable();
            $t->string('signature_ref')->nullable();
            $t->dateTime('signed_at')->nullable();
            $t->json('evidence')->nullable();
            $t->string('pdf_path')->nullable();
            $t->string('pdf_hash', 64)->nullable();
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->unsignedSmallInteger('term_index')->default(1);
            $t->string('accepted_by')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->dateTime('rejected_at')->nullable();
            $t->string('rejection_reason')->nullable();
            $t->timestamps();
            $t->index('status');
        });
        Schema::create('kyc_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained('consultants');
            $t->string('type'); // photo | id_document | tax_code_document | iban_proof
            $t->string('path');
            $t->string('original_name');
            $t->string('status')->default('pending'); // pending | approved | rejected
            $t->string('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['consultant_id', 'type']);
        });
        Schema::create('annual_fees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained('consultants');
            $t->unsignedSmallInteger('year_index');
            $t->decimal('amount', 15, 2);
            $t->dateTime('due_at');
            $t->dateTime('paid_at')->nullable();
            $t->string('method')->nullable(); // card | wallet | transfer
            $t->string('status')->default('due'); // due | paid | void
            $t->json('reminders_sent')->nullable();
            $t->timestamps();
            $t->unique(['consultant_id', 'year_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annual_fees');
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('ivd_contracts');
        Schema::table('consultants', function (Blueprint $t) {
            $t->dropConstrainedForeignId('user_id');
            $t->dropColumn(['kyc_status', 'iban', 'iban_holder', 'onboarding_completed_at', 'badge_number', 'badge_valid_until', 'badge_path']);
        });
        Schema::dropIfExists('customers');
        Schema::dropIfExists('profiles');
    }
};
