<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Visits patients ask for in the Hospify app (or the front desk books), confirmed by the facility. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->ulid('patient_id');
            $t->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $t->string('department', 100)->nullable();
            $t->text('reason')->nullable();                 // encrypted
            $t->date('preferred_date')->nullable();
            $t->string('preferred_time', 12)->nullable();   // morning | afternoon | evening | any
            $t->dateTime('scheduled_at')->nullable();
            $t->string('status', 20);                       // requested | confirmed | checked_in | completed | declined | cancelled | missed
            $t->string('facility_note', 500)->nullable();
            $t->string('requested_by', 10)->default('patient');  // patient | staff
            $t->boolean('reminded')->default(false);
            $t->json('history');
            $t->timestamps();
            $t->index(['facility_id', 'status', 'scheduled_at']);
            $t->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
