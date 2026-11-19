<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The ID a hospital checked in person at onboarding. One ID number can
        // belong to one NHC ID only, which stops duplicate records.
        Schema::create('patient_identity_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('number_hash', 64);
            $table->text('number');
            $table->string('last_four', 4);
            $table->string('facility_id');
            $table->foreignUlid('checked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('checked_at');
            $table->timestamps();

            $table->unique(['type', 'number_hash']);
        });

        // Staff are people too: their own health record is under their own NHC ID,
        // reached only through the patient side, never through their staff login.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('patient_id')->nullable()->unique()->after('phone')->constrained('patients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
            $table->dropUnique(['patient_id']);
            $table->dropColumn('patient_id');
        });
        Schema::dropIfExists('patient_identity_documents');
    }
};
