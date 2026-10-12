<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calculation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods');
            $table->string('type');                    // official | live
            $table->foreignId('comp_plan_version_id')->constrained('comp_plan_versions');
            $table->string('input_hash', 64);
            $table->string('status')->default('running'); // running | completed | failed
            $table->json('totals')->nullable();
            $table->json('warnings')->nullable();
            $table->json('decisions')->nullable();
            $table->text('error')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calculation_runs');
    }
};
