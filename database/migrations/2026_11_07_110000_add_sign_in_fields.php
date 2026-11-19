<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Patients sign in with their NHC ID and a code sent to this email.
        // It is also how NHC finds or creates their Hospify account.
        Schema::table('patients', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('phone_verified_at');
            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->timestamp('hospify_linked_at')->nullable()->after('hospify_ref');
        });

        // Staff sign in with email codes only, so there is no password to set.
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'email_verified_at', 'hospify_linked_at']);
        });

        // An empty hash never matches, so code-only staff stay unable to use a password.
        DB::table('users')->whereNull('password')->update(['password' => '']);

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable(false)->change();
        });
    }
};
