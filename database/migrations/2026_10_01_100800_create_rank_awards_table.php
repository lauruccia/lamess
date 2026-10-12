<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_awards', function (Blueprint $table) {
            $table->id();
            $table->string('award_key')->unique(); // "consulente:RANK"
            $table->foreignId('consultant_id')->constrained('consultants');
            $table->string('rank_code');
            $table->decimal('amount', 15, 2);
            $table->string('status'); // awarded | held | forfeited | not_awarded
            $table->string('achieved_period', 7);
            $table->string('reason')->default('');
            $table->timestamps();
        });
        Schema::create('rank_bonus_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rank_award_id')->constrained('rank_awards')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->unsignedSmallInteger('of');
            $table->decimal('amount', 15, 2);
            $table->string('due_period', 7);
            $table->string('status')->default('due'); // due | paid | held
            $table->timestamps();
            $table->unique(['rank_award_id', 'number']);
            $table->index(['due_period', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_bonus_installments');
        Schema::dropIfExists('rank_awards');
    }
};
