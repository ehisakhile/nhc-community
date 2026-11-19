<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The central health record (stage 4). Every row belongs to the hospital that
 * wrote it and to one patient. Rows are never rewritten or deleted: a hospital
 * corrects its record by adding to it, or by marking a row entered in error.
 * Only the access service (App\Modules\ClinicalRecords\ClinicalAccess) reads
 * across hospitals.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Who wrote it, where, and the "entered in error" marker every table shares.
        $common = function (Blueprint $t, bool $facilityRequired = true) {
            $t->ulid('id')->primary();
            $t->foreignUlid('patient_id')->constrained('patients')->restrictOnDelete();
            $t->string('facility_id')->nullable(! $facilityRequired);
            $t->foreign('facility_id')->references('id')->on('tenants')->restrictOnDelete();
            $t->foreignUlid('recorded_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('recorded_at');
            $t->timestamp('voided_at')->nullable();
            $t->foreignUlid('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('void_reason')->nullable();
            $t->timestamps();
            $t->index(['patient_id', 'facility_id']);
        };

        Schema::create('visits', function (Blueprint $t) use ($common) {
            $common($t);
            $t->string('type', 20);                       // outpatient | emergency | inpatient | telehealth
            $t->string('reason')->nullable();
            $t->string('status', 10)->default('open');    // open | closed
            $t->timestamp('closed_at')->nullable();
            $t->foreignUlid('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->index(['facility_id', 'status']);
        });

        Schema::create('vitals', function (Blueprint $t) use ($common) {
            $common($t);
            $t->foreignUlid('visit_id')->constrained('visits')->restrictOnDelete();
            $t->decimal('temperature_c', 4, 1)->nullable();
            $t->unsignedSmallInteger('pulse_bpm')->nullable();
            $t->unsignedSmallInteger('respiratory_rate')->nullable();
            $t->unsignedSmallInteger('bp_systolic')->nullable();
            $t->unsignedSmallInteger('bp_diastolic')->nullable();
            $t->unsignedTinyInteger('spo2')->nullable();
            $t->decimal('weight_kg', 5, 1)->nullable();
            $t->decimal('height_cm', 5, 1)->nullable();
            $t->string('note')->nullable();
        });

        Schema::create('clinical_notes', function (Blueprint $t) use ($common) {
            $common($t);
            $t->foreignUlid('visit_id')->constrained('visits')->restrictOnDelete();
            $t->string('kind', 30);                       // consultation | progress | discharge_summary
            $t->text('body');                             // encrypted
            $t->foreignUlid('amends_id')->nullable()->constrained('clinical_notes')->restrictOnDelete();
        });

        Schema::create('diagnoses', function (Blueprint $t) use ($common) {
            $common($t);
            $t->foreignUlid('visit_id')->nullable()->constrained('visits')->restrictOnDelete();
            $t->string('code', 10)->nullable();           // ICD-10, when known
            $t->string('description');
            $t->boolean('chronic')->default(false);
            $t->string('status', 10)->default('active');  // active | resolved
            $t->timestamp('resolved_at')->nullable();
        });

        Schema::create('allergies', function (Blueprint $t) use ($common) {
            $common($t, facilityRequired: false);         // a patient can add their own
            $t->string('substance');
            $t->string('reaction')->nullable();
            $t->string('severity', 10)->default('unknown'); // mild | moderate | severe | unknown
            $t->string('status', 10)->default('active');  // active | inactive
            $t->string('source', 10)->default('clinician'); // clinician | patient
            $t->foreignUlid('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('confirmed_at')->nullable();
        });

        Schema::create('prescriptions', function (Blueprint $t) use ($common) {
            $common($t);
            $t->foreignUlid('visit_id')->constrained('visits')->restrictOnDelete();
            $t->string('drug');
            $t->string('strength', 50)->nullable();
            $t->string('form', 30)->nullable();           // tablet, syrup, injection...
            $t->string('dose', 50);
            $t->string('route', 30)->nullable();
            $t->string('frequency', 50);
            $t->unsignedSmallInteger('duration_days')->nullable();
            $t->string('quantity', 30)->nullable();
            $t->string('instructions')->nullable();
            $t->string('status', 10)->default('active');  // active | stopped | completed
            $t->timestamp('stopped_at')->nullable();
            $t->string('stop_reason')->nullable();
            $t->foreignUlid('dispensed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('dispensed_at')->nullable();
        });

        Schema::create('vaccinations', function (Blueprint $t) use ($common) {
            $common($t);
            $t->string('vaccine');
            $t->unsignedTinyInteger('dose_number')->nullable();
            $t->date('given_on');
            $t->string('batch', 50)->nullable();
        });

        // Facts about the person that change rarely: blood group, genotype.
        Schema::create('patient_facts', function (Blueprint $t) use ($common) {
            $common($t);
            $t->string('kind', 20);                       // blood_group | genotype
            $t->string('value', 10);
        });

        // Break-glass: a clinician sees the summary from every hospital, for a day.
        Schema::create('emergency_accesses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('patient_id')->constrained('patients')->restrictOnDelete();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->restrictOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->restrictOnDelete();
            $t->text('reason');
            $t->timestamp('expires_at');
            $t->timestamp('patient_told_at')->nullable();
            $t->timestamps();
            $t->index(['patient_id', 'facility_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        foreach (['emergency_accesses', 'patient_facts', 'vaccinations', 'prescriptions', 'allergies', 'diagnoses', 'clinical_notes', 'vitals', 'visits'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
