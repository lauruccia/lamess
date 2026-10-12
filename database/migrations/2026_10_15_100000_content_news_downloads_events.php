<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Contenuti per la rete: novità, materiali scaricabili con filigrana nominativa, eventi e corsi con iscrizioni. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_posts', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('slug')->unique();
            $t->string('excerpt', 300)->nullable();
            $t->text('body');
            $t->string('audience', 20)->default('all');          // all | consultants | fans
            $t->string('status', 20)->default('draft');          // draft | published
            $t->dateTime('published_at')->nullable();
            $t->boolean('pinned')->default(false);
            $t->string('author_label')->nullable();
            $t->timestamps();
            $t->index(['status', 'published_at']);
        });
        Schema::create('downloads', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('category', 60)->nullable();
            $t->string('file_path');
            $t->string('original_name');
            $t->string('mime', 100)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('audience', 20)->default('consultants'); // all | consultants
            $t->string('min_rank_code', 20)->nullable();        // qualifica minima per scaricarlo
            $t->boolean('watermark')->default(true);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('download_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('download_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('consultant_id')->nullable()->constrained()->nullOnDelete();
            $t->string('ip', 45)->nullable();
            $t->boolean('watermarked')->default(false);
            $t->string('note')->nullable();                      // perché il file è uscito senza filigrana
            $t->timestamp('created_at')->nullable();
            $t->index(['download_id', 'created_at']);
        });
        Schema::create('corporate_events', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('kind', 20)->default('event');            // event | course | webinar
            $t->dateTime('starts_at');
            $t->dateTime('ends_at')->nullable();
            $t->string('location')->nullable();
            $t->string('online_url')->nullable();
            $t->unsignedInteger('capacity')->nullable();         // vuoto = posti illimitati
            $t->dateTime('registration_deadline')->nullable();
            $t->string('audience', 20)->default('all');          // all | consultants | fans
            $t->string('status', 20)->default('draft');          // draft | published | cancelled
            $t->timestamps();
            $t->index(['status', 'starts_at']);
        });
        Schema::create('event_registrations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('corporate_event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('registered');     // registered | waitlist | cancelled
            $t->dateTime('cancelled_at')->nullable();
            $t->timestamps();
            $t->unique(['corporate_event_id', 'user_id']);
        });
    }

    public function down(): void
    {
        foreach (['event_registrations', 'corporate_events', 'download_logs', 'downloads', 'news_posts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
