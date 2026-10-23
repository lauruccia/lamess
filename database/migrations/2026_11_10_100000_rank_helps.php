<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Agenti regalati»: aiuto dell'azienda a un incaricato per qualificarsi. Aggiunge N agenti virtuali di una data qualifica
 * al 1° livello sotto di lui: contano solo per i requisiti della qualifica (Pearl al 1° livello, struttura), non per
 * i compensi, non per i clienti e non sono incaricati reali della rete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_helps', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('consultant_id')->constrained('consultants')->cascadeOnDelete();
            $t->string('rank_code', 40);
            $t->unsignedSmallInteger('quantity');
            $t->string('reason', 255);
            $t->dateTime('starts_at');
            $t->date('valid_until')->nullable();
            $t->string('status', 20)->default('confirmed');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_helps');
    }
};
