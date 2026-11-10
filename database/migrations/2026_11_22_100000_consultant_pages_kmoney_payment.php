<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pagamento automatico della pagina personale con KMoney: la richiesta di pagamento in corso e l'ultima già incassata (idempotenza dell'avviso). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultant_pages', function (Blueprint $t) {
            $t->string('kmoney_request_uuid', 64)->nullable()->after('activation_note');   // richiesta di pagamento aperta su KMoney
            $t->string('kmoney_paid_uuid', 64)->nullable()->after('kmoney_request_uuid');   // ultima richiesta incassata e già lavorata
        });
    }

    public function down(): void
    {
        Schema::table('consultant_pages', function (Blueprint $t) {
            $t->dropColumn(['kmoney_request_uuid', 'kmoney_paid_uuid']);
        });
    }
};
