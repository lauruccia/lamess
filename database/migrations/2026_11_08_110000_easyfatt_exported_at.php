<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quando il documento è stato esportato per Easyfatt (vuoto = non ancora): serve a non importarlo due volte.
        foreach (['invoices', 'credit_notes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('easyfatt_exported_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['invoices', 'credit_notes'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('easyfatt_exported_at');
            });
        }
    }
};
