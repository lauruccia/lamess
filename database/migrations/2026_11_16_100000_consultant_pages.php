<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pagina personale pubblica dell'incaricato (/i/{slug}): contenuto in uso, proposta in revisione, stato di pubblicazione. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultant_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('consultant_id')->unique()->constrained()->cascadeOnDelete();
            $t->string('slug', 60)->unique();
            // contenuto in uso (quello che vede il pubblico)
            $t->string('display_name', 120)->nullable();
            $t->string('headline', 160)->nullable();
            $t->text('bio')->nullable();
            $t->text('story')->nullable();
            $t->string('photo_path')->nullable();
            $t->string('whatsapp', 30)->nullable();
            $t->string('email_public', 190)->nullable();
            $t->string('instagram', 300)->nullable();
            $t->string('facebook', 300)->nullable();
            $t->string('tiktok', 300)->nullable();
            $t->string('linkedin', 300)->nullable();
            $t->json('media')->nullable();
            // modifiche in attesa di approvazione (stessi campi del contenuto in uso)
            $t->json('proposal')->nullable();
            $t->string('review', 12)->default('none');   // none | pending | rejected
            $t->text('review_note')->nullable();
            $t->boolean('enabled')->default(false);      // l'incaricato la vuole online
            $t->boolean('blocked')->default(false);      // nascosta dall'admin
            $t->text('blocked_note')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->unsignedInteger('views')->default(0);
            $t->timestamps();
            $t->index(['enabled', 'blocked']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultant_pages');
    }
};
