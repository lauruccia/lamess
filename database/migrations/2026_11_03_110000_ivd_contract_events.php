<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Registro degli accessi alla Lettera di incarico (prima e dopo la firma) e IP di creazione, per il certificato di completamento. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ivd_contract_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ivd_contract_id')->constrained('ivd_contracts')->cascadeOnDelete();
            $t->string('event', 30);                       // created | viewed | signed | downloaded
            $t->boolean('after_signing')->default(false);
            $t->string('ip', 64)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('occurred_at');
            $t->index(['ivd_contract_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ivd_contract_events');
    }
};
