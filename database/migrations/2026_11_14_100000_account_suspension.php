<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sospensione e ban dell'account: l'utente non entra più e sparisce da elenchi, alberi e link referral. I dati restano. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->timestamp('suspended_at')->nullable()->index();
            $t->string('suspension_type', 16)->nullable();   // suspended | banned
            $t->string('suspension_reason', 500)->nullable();
            $t->unsignedBigInteger('suspended_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t): void {
            $t->dropIndex(['suspended_at']);
            $t->dropColumn(['suspended_at', 'suspension_type', 'suspension_reason', 'suspended_by']);
        });
    }
};
