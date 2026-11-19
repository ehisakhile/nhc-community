<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hospital A asks hospital B for a patient's records. The patient
        // approves, then B releases; each can narrow what A asked for.
        Schema::create('record_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('requesting_facility_id');
            $table->foreign('requesting_facility_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->string('holding_facility_id');
            $table->foreign('holding_facility_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreignUlid('requested_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');

            // What A asked for.
            $table->json('record_types');
            $table->string('access_type', 10);          // view | copy
            $table->unsignedSmallInteger('duration_days')->nullable();

            // What is granted, narrowed by the patient and then by B.
            $table->json('granted_record_types')->nullable();
            $table->string('granted_access_type', 10)->nullable();
            $table->unsignedSmallInteger('granted_duration_days')->nullable();

            $table->string('status', 20)->index();      // awaiting_patient | awaiting_hospital | approved | declined | cancelled | withdrawn
            $table->timestamp('patient_decided_at')->nullable();
            $table->string('patient_channel', 10)->nullable();   // sms | app
            $table->foreignUlid('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->string('declined_by', 10)->nullable();       // patient | hospital | system
            $table->string('decline_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['holding_facility_id', 'status']);
            $table->index(['requesting_facility_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_requests');
    }
};
