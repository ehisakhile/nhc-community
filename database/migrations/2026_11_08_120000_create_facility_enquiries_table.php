<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hospitals asking to join NHC from the website. Our team follows up by hand.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facility_enquiries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('facility_name');
            $table->string('facility_type', 64);
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone', 32);
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('department_count', 16)->nullable();
            $table->string('status', 16)->default('new'); // new, contacted, onboarded, closed
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_enquiries');
    }
};
