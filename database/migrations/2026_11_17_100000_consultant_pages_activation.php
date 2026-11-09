<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Attivazione della pagina personale: su richiesta, a pagamento, valida un anno. Le pagine già esistenti risultano attivate dal Consorzio, senza scadenza. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_pages', function (Blueprint $t) {
            $t->string('activation', 12)->default('none')->after('blocked_note');   // none | active
            $t->string('activation_source', 8)->nullable()->after('activation');   // paid | free
            $t->timestamp('activation_requested_at')->nullable()->after('activation_source');
            $t->timestamp('activated_at')->nullable()->after('activation_requested_at');
            $t->timestamp('activation_expires_at')->nullable()->after('activated_at');   // vuoto = senza scadenza
            $t->timestamp('activation_reminded_at')->nullable()->after('activation_expires_at');
            $t->text('activation_note')->nullable()->after('activation_reminded_at');
        });
        DB::table('consultant_pages')->update(['activation' => 'active', 'activation_source' => 'free', 'activated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('consultant_pages', function (Blueprint $t) {
            $t->dropColumn(['activation', 'activation_source', 'activation_requested_at', 'activated_at', 'activation_expires_at', 'activation_reminded_at', 'activation_note']);
        });
    }
};
