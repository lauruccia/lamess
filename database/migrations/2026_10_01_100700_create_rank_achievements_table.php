<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_id')->constrained('consultants');
            $table->string('rank_code');
            $table->dateTime('achieved_at');
            $table->date('effective_from');
            $table->foreignId('period_id')->nullable()->constrained('periods');
            $table->string('source')->default('calculated'); // calculated | manual | migration
            $table->json('detail')->nullable();
            $table->timestamps();
            $table->unique(['consultant_id', 'rank_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_achievements');
    }
};
