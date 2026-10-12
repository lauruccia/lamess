<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultants', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('referral_code')->nullable()->unique();
            $table->string('name');
            $table->foreignId('sponsor_id')->nullable()->constrained('consultants')->restrictOnDelete();
            $table->foreignId('original_sponsor_id')->nullable()->constrained('consultants')->restrictOnDelete();
            $table->string('status')->default('active'); // pending | active | suspended | terminated
            $table->dateTime('since');                   // firma della Lettera IVD
            $table->dateTime('terminated_at')->nullable();
            $table->boolean('onboarding_complete')->default(false);
            $table->boolean('bonus_hold')->default(false);
            $table->string('rank_cap_code')->nullable();
            $table->timestamps();
            $table->index('sponsor_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultants');
    }
};
