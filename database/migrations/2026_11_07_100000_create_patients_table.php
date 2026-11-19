<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->ulid('id')->primary();

            // The NHC ID: fixed for life and never reused. nhc_base is the first
            // owner's number; the suffix separates dependants and recycled SIMs.
            $table->string('nhc_id', 24)->unique();
            $table->string('nhc_base', 10);
            $table->unsignedSmallInteger('nhc_suffix')->default(1);
            $table->string('country_code', 3)->default('234');

            // The phone on file. It can change; the ID can't. OTPs go here.
            $table->string('phone', 10)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->foreignUlid('guardian_id')->nullable()->constrained('patients')->restrictOnDelete();

            $table->string('first_name');
            $table->string('last_name');
            $table->string('other_names')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();

            // Random reference to the Hospify account. Never the Hospify user ID.
            $table->string('hospify_ref', 64)->nullable()->unique();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['nhc_base', 'nhc_suffix']);
        });

        Schema::create('patient_phones', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('phone', 10)->index();
            $table->timestamp('verified_at');
            $table->timestamp('replaced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_phones');
        Schema::dropIfExists('patients');
    }
};
