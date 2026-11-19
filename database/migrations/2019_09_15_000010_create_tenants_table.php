<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->string('name');
            $table->string('type', 50);
            $table->string('status', 20)->default('pending')->index();
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('lga', 100)->nullable();
            // The same hospital in Hospify: its Org account and directory listing.
            $table->unsignedBigInteger('hospify_organisation_id')->nullable()->unique();
            $table->unsignedBigInteger('hospify_provider_id')->nullable()->unique();

            $table->timestamps();
            $table->json('data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
