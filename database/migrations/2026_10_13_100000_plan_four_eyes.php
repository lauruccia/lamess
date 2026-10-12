<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Approvazione a quattro occhi della pubblicazione del piano: chi chiede, quando, per quando e perché. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comp_plan_versions', function (Blueprint $t) {
            $t->unsignedBigInteger('requested_by_id')->nullable()->after('approved_by');
            $t->string('requested_by_label')->nullable()->after('requested_by_id');
            $t->dateTime('requested_at')->nullable()->after('requested_by_label');
            $t->date('requested_effective_from')->nullable()->after('requested_at');
            $t->text('request_reason')->nullable()->after('requested_effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('comp_plan_versions', fn (Blueprint $t) => $t->dropColumn(['requested_by_id', 'requested_by_label', 'requested_at', 'requested_effective_from', 'request_reason']));
    }
};
