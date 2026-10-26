<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Documenti legali modificabili dal back-office (per ora: «Principi generali e Regolamento»), con storico delle modifiche. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 40)->unique();
            $t->string('version', 30);
            $t->longText('body');
            $t->text('articles_1341')->nullable();
            $t->text('change_summary')->nullable();
            $t->unsignedSmallInteger('revision')->default(0);
            $t->string('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('legal_document_edits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('legal_document_id')->constrained('legal_documents')->cascadeOnDelete();
            $t->string('kind', 20);                 // initial | typo | new_version
            $t->string('version', 30);
            $t->text('summary')->nullable();
            $t->longText('body_before')->nullable();
            $t->string('actor')->nullable();
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_document_edits');
        Schema::dropIfExists('legal_documents');
    }
};
