<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_period_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('calculation_runs')->cascadeOnDelete();
            $table->foreignId('period_id')->constrained('periods');
            $table->foreignId('consultant_id')->constrained('consultants');
            $table->decimal('personal_pvf', 15, 2);
            $table->decimal('personal_pvc', 15, 2);
            $table->decimal('personal_cv', 15, 2);
            $table->decimal('group_pv', 15, 2);
            $table->decimal('activity_points', 15, 2);
            $table->decimal('pr_opening', 15, 2);
            $table->decimal('pr_closing', 15, 2);
            $table->decimal('group_pr_closing', 15, 2);
            $table->boolean('is_active');
            $table->string('active_reason')->default('');
            $table->string('first_activity_period', 7)->nullable();
            $table->string('achieved_rank');
            $table->string('effective_rank');
            $table->string('indirect_status')->default('');
            $table->string('matching_status')->default('');
            $table->json('next_rank');
            $table->json('lines_pr');
            $table->unique(['run_id', 'consultant_id']);
            $table->index(['period_id', 'consultant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_period_stats');
    }
};
