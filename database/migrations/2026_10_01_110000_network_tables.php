<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultants', function (Blueprint $table) {
            $table->string('person_key')->nullable()->index(); // codice fiscale: identifica la persona fisica
            $table->string('termination_reason')->nullable();
        });
        Schema::create('network_paths', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ancestor_id')->constrained('consultants')->cascadeOnDelete();
            $table->foreignId('descendant_id')->constrained('consultants')->cascadeOnDelete();
            $table->unsignedSmallInteger('depth');
            $table->foreignId('leg_root_id')->nullable()->constrained('consultants')->cascadeOnDelete();
            $table->unique(['ancestor_id', 'descendant_id']);
            $table->index(['ancestor_id', 'depth']);
            $table->index('descendant_id');
        });
        Schema::create('sponsor_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_id')->constrained('consultants');
            $table->foreignId('from_sponsor_id')->nullable()->constrained('consultants');
            $table->foreignId('to_sponsor_id')->nullable()->constrained('consultants');
            $table->string('reason'); // compression | technical_fix | transfer
            $table->foreignId('period_id')->nullable()->constrained('periods');
            $table->string('approved_by')->nullable();
            $table->timestamps();
        });
        Schema::create('consultant_status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultant_id')->constrained('consultants');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('reason')->default('');
            $table->string('changed_by')->nullable();
            $table->dateTime('changed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_status_changes');
        Schema::dropIfExists('sponsor_history');
        Schema::dropIfExists('network_paths');
        Schema::table('consultants', function (Blueprint $table) {
            $table->dropColumn(['person_key', 'termination_reason']);
        });
    }
};
