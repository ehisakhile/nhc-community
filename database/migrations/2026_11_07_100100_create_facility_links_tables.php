<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('facility_id');
            $table->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('status', 20)->default('invited');
            $table->string('licence_number', 50)->nullable();
            $table->timestamp('licence_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'facility_id']);
        });

        // A patient joining a hospital. Gives that hospital the profile only.
        Schema::create('patient_facility_links', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('facility_id');
            $table->foreign('facility_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->string('chart_number', 50)->nullable();
            $table->string('joined_via', 20);
            $table->timestamp('confirmed_at');
            $table->timestamps();

            $table->unique(['patient_id', 'facility_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_facility_links');
        Schema::dropIfExists('facility_memberships');
    }
};
