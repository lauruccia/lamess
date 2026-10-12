<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comp_plan_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('draft'); // draft | in_approval | active | archived
            $table->json('data');                       // piano completo (vedi config/lamess/*.json)
            $table->string('content_hash', 64);
            $table->string('approved_by')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_plan_versions');
    }
};
