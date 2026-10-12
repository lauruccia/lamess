<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Registro dei Bonus di Struttura (piano Incaricati): calcolati ogni settimana, confermati e pagati una sola volta. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structure_bonuses', function (Blueprint $table): void {
            $table->id();
            $table->string('bonus_key')->unique();
            $table->foreignId('beneficiary_id')->constrained('consultants');
            $table->foreignId('source_consultant_id')->nullable()->constrained('consultants');
            $table->foreignId('period_id')->nullable()->constrained('periods')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->decimal('latest_amount', 12, 2)->nullable();
            $table->string('engine_status', 20)->default('accrued');
            $table->string('status', 20)->default('calculated'); // calculated | confirmed | paid | cancelled
            $table->json('explanation')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_reference')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['status', 'beneficiary_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_bonuses');
    }
};
