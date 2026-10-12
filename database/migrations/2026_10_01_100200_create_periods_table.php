<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->string('code', 7)->unique(); // YYYY-MM
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status')->default('open'); // open | calculating | review | closed
            $table->foreignId('comp_plan_version_id')->constrained('comp_plan_versions');
            $table->unsignedBigInteger('official_run_id')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('periods');
    }
};
