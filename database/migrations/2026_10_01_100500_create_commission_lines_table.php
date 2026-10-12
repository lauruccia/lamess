<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('calculation_runs')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods');
            $table->foreignId('beneficiary_id')->constrained('consultants');
            $table->string('line_key');
            $table->string('bonus_type'); // direct | team | matching | rank | rank_installment | fsb | clawback
            $table->decimal('base_amount', 15, 2);
            $table->decimal('pct', 7, 4)->nullable();
            $table->decimal('amount', 15, 2);
            $table->foreignId('source_movement_id')->nullable()->constrained('pv_movements');
            $table->foreignId('source_consultant_id')->nullable()->constrained('consultants');
            $table->unsignedSmallInteger('level')->nullable();
            $table->string('engine_status')->default('accrued'); // accrued | held | excluded
            $table->string('reason')->default('');
            $table->json('explanation');
            $table->json('decisions');
            $table->string('status')->default('draft'); // draft | locked
            $table->timestamps();
            $table->unique(['run_id', 'line_key']);
            $table->index(['beneficiary_id', 'period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_lines');
    }
};
