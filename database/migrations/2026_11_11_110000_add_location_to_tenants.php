<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Where a facility is, and its NIPOST building postcode (looked up through Hospify). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $t) {
            $t->string('address', 500)->nullable()->after('lga');
            $t->decimal('latitude', 10, 7)->nullable()->after('address');
            $t->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $t->string('postcode', 20)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn(['address', 'latitude', 'longitude', 'postcode']));
    }
};
