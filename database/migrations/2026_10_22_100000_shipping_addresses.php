<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rubrica degli indirizzi di spedizione (fino a lamess.max_shipping_addresses per utente); la fatturazione resta un solo indirizzo nel profilo.
        Schema::create('shipping_addresses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('label', 60)->nullable();        // es. «Casa», «Ufficio», «Mare»
            $t->string('recipient', 190)->nullable();   // a chi consegnare (vuoto = intestatario)
            $t->string('address', 190);
            $t->string('city', 120);
            $t->string('zip', 10);
            $t->string('province', 3)->nullable();
            $t->string('phone', 30)->nullable();
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_addresses');
    }
};
