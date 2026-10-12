<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro funzionalità (interruttori on/off di tab, viste, link, pulsanti) e note interne sulla scheda consulente.
 * Le funzionalità si dichiarano nel codice (FeatureRegistry); qui stanno solo le scelte dell'admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ui_feature_settings', function (Blueprint $t) {
            $t->id();
            $t->string('feature_key', 120);
            $t->string('scope_type', 10)->default('all'); // all | role | user
            $t->string('scope_id', 60)->default('');       // '' per «tutti», nome del ruolo, id dell'utente
            $t->boolean('enabled');
            $t->boolean('locked_by_superadmin')->default(false);
            $t->string('reason', 250)->nullable();
            $t->string('updated_by', 120)->nullable();      // etichetta pubblica (Actor::label): mai l'identità del super_admin
            $t->timestamps();
            $t->unique(['feature_key', 'scope_type', 'scope_id']);
        });

        // Note interne degli admin sul consulente: si aggiungono, non si modificano né si cancellano da interfaccia
        Schema::create('consultant_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->constrained('consultants');
            $t->text('body');
            $t->boolean('pinned')->default(false);
            $t->string('author_label', 120);
            $t->timestamps();
            $t->index(['consultant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_notes');
        Schema::dropIfExists('ui_feature_settings');
    }
};
