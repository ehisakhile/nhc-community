<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Some Greenbook products list 20+ ingredients (over 255 characters). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pharmacy_listings', function (Blueprint $t) {
            $t->text('ingredient')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_listings', function (Blueprint $t) {
            $t->string('ingredient')->nullable()->change();
        });
    }
};
