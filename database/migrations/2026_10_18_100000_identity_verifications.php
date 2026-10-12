<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Verifiche d'identità online (documento + selfie) fatte da un provider esterno. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_verifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 30);
            $t->string('ref')->unique();
            $t->string('status', 20)->default('started'); // started | verified | requires_input | canceled | superseded
            $t->string('failure_reason')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_verifications');
    }
};
