<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pv_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods');
            $table->foreignId('consultant_id')->constrained('consultants'); // consulente che genera i punti
            $table->string('kind');                        // pvf | pvc
            $table->string('type')->default('sale');       // sale | refund | adjustment | migration
            $table->string('status')->default('confirmed'); // pending_payment | confirmed | cancelled
            $table->dateTime('event_at');
            $table->decimal('pv', 15, 2);
            $table->decimal('cv', 15, 2);
            $table->foreignId('reverses_movement_id')->nullable()->constrained('pv_movements');
            $table->boolean('excluded_from_bonus')->default(false);
            $table->string('external_ref')->nullable()->unique();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'status']);
            $table->index('consultant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pv_movements');
    }
};
