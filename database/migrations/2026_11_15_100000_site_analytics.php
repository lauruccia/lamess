<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statistiche del sito (pagina «Statistiche sito»): una riga per visita e una per pagina vista.
 * Nessun dato personale oltre all'utente collegato: l'IP si conserva solo troncato.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('analytics_visits')) {
            Schema::create('analytics_visits', function (Blueprint $t): void {
                $t->id();
                $t->string('vid', 26)->unique();
                $t->timestamp('started_at')->index();
                $t->timestamp('last_seen_at')->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->boolean('is_staff')->default(false);
                $t->string('landing_area', 5);                  // front | back
                $t->string('landing_path', 255);
                $t->string('current_area', 5);
                $t->string('current_path', 255);
                $t->string('referrer_host', 120)->nullable();
                $t->string('source', 40)->index();              // Google, Instagram, Diretto…
                $t->string('medium', 20)->nullable();           // organico, social, a pagamento, email, referral, diretto
                $t->string('campaign', 120)->nullable();
                $t->string('referral_code', 40)->nullable();
                $t->string('country', 2)->nullable();
                $t->string('region', 80)->nullable();
                $t->string('city', 80)->nullable();
                $t->string('device', 10);                       // desktop | mobile | tablet
                $t->string('browser', 30);
                $t->string('os', 30);
                $t->string('ip_masked', 45)->nullable();
                $t->unsignedInteger('hits')->default(0);
            });
        }

        if (! Schema::hasTable('analytics_hits')) {
            Schema::create('analytics_hits', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('visit_id')->index();
                $t->timestamp('created_at')->index();
                $t->string('area', 5);
                $t->string('path', 255);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('search_term', 120)->nullable();
                $t->unsignedInteger('search_results')->nullable();
                $t->index(['area', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_hits');
        Schema::dropIfExists('analytics_visits');
    }
};
