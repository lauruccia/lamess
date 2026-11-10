<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lingue del sito e testi tradotti. Il testo italiano è la chiave: `source` è la frase come sta nella vista,
 * `key_hash` (md5) serve solo all'indice univoco perché un testo lungo non si può indicizzare.
 * Lo stato di una traduzione è: missing (vuota), auto (tradotta da una macchina, da rivedere), approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $t) {
            $t->id();
            $t->string('code', 8)->unique();
            $t->string('name', 60);
            $t->string('native_name', 60);
            $t->string('flag', 20)->nullable();
            $t->string('status', 10)->default('draft'); // draft | active
            $t->boolean('is_default')->default(false);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::create('translation_strings', function (Blueprint $t) {
            $t->id();
            $t->string('locale', 8);
            $t->char('key_hash', 32);
            $t->longText('source');
            $t->longText('text')->nullable();
            $t->string('status', 10)->default('missing'); // missing | auto | approved
            $t->boolean('orphan')->default(false);
            $t->timestamps();
            $t->unique(['locale', 'key_hash']);
            $t->index(['locale', 'status']);
        });

        Schema::table('users', function (Blueprint $t) {
            $t->string('locale', 8)->nullable()->after('email');
        });

        $now = now();
        $rows = [
            ['it', 'Italian', 'Italiano', 'it', 'active', true, 1],
            ['en', 'English', 'English', 'gb', 'draft', false, 2],
            ['nl', 'Dutch', 'Nederlands', 'nl', 'draft', false, 3],
            ['fr', 'French', 'Français', 'fr', 'draft', false, 4],
            ['de', 'German', 'Deutsch', 'de', 'draft', false, 5],
            ['es', 'Spanish', 'Español', 'es', 'draft', false, 6],
        ];
        foreach ($rows as [$code, $name, $native, $flag, $status, $default, $sort]) {
            DB::table('languages')->insert([
                'code' => $code, 'name' => $name, 'native_name' => $native, 'flag' => $flag, 'status' => $status,
                'is_default' => $default, 'sort' => $sort, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn('locale');
        });
        Schema::dropIfExists('translation_strings');
        Schema::dropIfExists('languages');
    }
};
