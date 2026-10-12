<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Impostazioni di sistema modificabili dal super_admin (sovrascrivono i valori di config/lamess.php / .env). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 120)->unique();
            $t->text('value')->nullable();       // JSON di un valore scalare
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
