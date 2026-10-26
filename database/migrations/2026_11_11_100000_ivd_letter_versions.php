<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Testo della Lettera di Incarico modificabile dal back-office, con storico.
 * - ivd_letter_versions: ogni versione contrattuale (testo intero, riassunto delle modifiche, articoli da approvare ex 1341 c.c.);
 * - ivd_letter_edits: ogni salvataggio (primo testo, correzione di refuso, modifica contrattuale) con il testo precedente;
 * - ivd_letter_signatures: la richiesta di firma di una versione agli incaricati già registrati, con le prove della firma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ivd_letter_versions', function (Blueprint $t) {
            $t->id();
            $t->string('version', 30)->unique();
            $t->longText('body');
            $t->text('change_summary')->nullable();      // «cosa cambia», mostrato agli incaricati
            $t->text('articles_1341')->nullable();       // articoli da approvare espressamente; vuoto = elenco standard
            $t->boolean('is_current')->default(false)->index();
            $t->unsignedSmallInteger('revision')->default(0); // quante correzioni di refuso dopo la pubblicazione
            $t->dateTime('published_at')->nullable();
            $t->string('published_by')->nullable();
            $t->timestamps();
        });
        Schema::create('ivd_letter_edits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ivd_letter_version_id')->constrained('ivd_letter_versions')->cascadeOnDelete();
            $t->string('kind', 20);                      // initial | typo | contractual
            $t->text('summary')->nullable();
            $t->longText('body_before')->nullable();
            $t->unsignedInteger('words_changed')->default(0);
            $t->string('actor')->nullable();
            $t->dateTime('created_at');
        });
        Schema::create('ivd_letter_signatures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ivd_letter_version_id')->constrained('ivd_letter_versions')->cascadeOnDelete();
            $t->foreignId('consultant_id')->constrained('consultants')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('status', 20)->default('pending'); // pending | signed | cancelled
            $t->string('sent_by')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('last_reminder_at')->nullable();
            $t->dateTime('viewed_at')->nullable();
            $t->string('signature_provider')->nullable();
            $t->string('signature_ref')->nullable();
            $t->dateTime('signed_at')->nullable();
            $t->json('evidence')->nullable();
            $t->string('pdf_path')->nullable();
            $t->string('pdf_hash', 64)->nullable();
            $t->timestamps();
            $t->unique(['ivd_letter_version_id', 'consultant_id']);
            $t->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ivd_letter_signatures');
        Schema::dropIfExists('ivd_letter_edits');
        Schema::dropIfExists('ivd_letter_versions');
    }
};
