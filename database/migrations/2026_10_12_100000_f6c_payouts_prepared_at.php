<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('periods', function (Blueprint $t): void {
            $t->timestamp('payouts_prepared_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('periods', function (Blueprint $t): void {
            $t->dropColumn('payouts_prepared_at');
        });
    }
};
