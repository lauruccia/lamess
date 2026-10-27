<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** BIC/SWIFT della banca del beneficiario: serve per i bonifici fuori area SEPA. Sui pagamenti e sui prelievi è una copia al momento della richiesta. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['consultants', 'payouts', 'withdrawal_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('bic', 11)->nullable()->after('iban_holder');
            });
        }
    }

    public function down(): void
    {
        foreach (['consultants', 'payouts', 'withdrawal_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('bic');
            });
        }
    }
};
