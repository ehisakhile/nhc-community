<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_services', function (Blueprint $table): void {
            $table->string('kind', 32)->change();
            $table->unsignedBigInteger('price_kobo')->change();
        });
        Schema::table('care_bookings', function (Blueprint $table): void {
            $table->string('kind', 32)->change();
            $table->unsignedBigInteger('price_kobo')->change();
            $table->unsignedBigInteger('fee_kobo')->default(0)->change();
            $table->unsignedBigInteger('total_kobo')->change();
            $table->unsignedBigInteger('commission_kobo')->default(0)->change();
        });
    }

    public function down(): void
    {
        foreach (['care_services' => ['price_kobo'], 'care_bookings' => ['price_kobo', 'fee_kobo', 'total_kobo', 'commission_kobo']] as $name => $amounts) {
            $query = DB::table($name)->whereRaw('length(kind) > 16');
            foreach ($amounts as $amount) {
                $query->orWhere($amount, '>', 4294967295);
            }
            if ($query->exists()) {
                throw new RuntimeException('Migrate consultation kinds and large amounts before narrowing the care schema.');
            }
        }
        Schema::table('care_services', function (Blueprint $table): void {
            $table->string('kind', 16)->change();
            $table->unsignedInteger('price_kobo')->change();
        });
        Schema::table('care_bookings', function (Blueprint $table): void {
            $table->string('kind', 16)->change();
            $table->unsignedInteger('price_kobo')->change();
            $table->unsignedInteger('fee_kobo')->default(0)->change();
            $table->unsignedInteger('total_kobo')->change();
            $table->unsignedInteger('commission_kobo')->default(0)->change();
        });
    }
};
