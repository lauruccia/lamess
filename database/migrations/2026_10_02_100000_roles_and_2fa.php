<?php

use App\Domain\Access\Roles;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->text('app_authentication_secret')->nullable();
            $t->text('app_authentication_recovery_codes')->nullable();
        });

        Roles::sync();
        User::query()->where('is_admin', true)->get()->each->assignRole(Roles::SUPER_ADMIN);

        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
            $t->dropColumn(['app_authentication_secret', 'app_authentication_recovery_codes']);
        });
    }
};
