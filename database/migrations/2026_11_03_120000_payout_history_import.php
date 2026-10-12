<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prelievi, pagamenti e ricevute storici di Odoo: `source` = 'odoo', `external_ref` rende l'importazione ripetibile. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payouts', 'withdrawal_requests'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('source', 20)->nullable();
                $t->string('external_ref', 80)->nullable()->unique();
            });
        }
        Schema::table('commission_receipts', function (Blueprint $t) {
            $t->string('source', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commission_receipts', fn (Blueprint $t) => $t->dropColumn('source'));
        foreach (['withdrawal_requests', 'payouts'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropUnique("{$table}_external_ref_unique");
                $t->dropColumn(['source', 'external_ref']);
            });
        }
    }
};
