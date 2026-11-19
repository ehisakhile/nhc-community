<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookable hours (app/Modules/Scheduling, spec 0001): when a facility takes
 * bookings, the days it's closed, how far ahead and with how much notice, and
 * the places held while a patient pays. Visits and care bookings remember the
 * slot the patient picked.
 *
 * Windows and closed dates are Lagos wall clock; every datetime is UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_hours', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->ulid('service_id')->nullable();               // null: the facility timetable
            $t->foreign('service_id')->references('id')->on('care_services')->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday');               // ISO: 1 Monday .. 7 Sunday
            $t->time('opens_at');
            $t->time('closes_at');                            // at most 23:59, never past midnight
            $t->unsignedSmallInteger('slot_minutes');         // 15 | 20 | 30 | 45 | 60
            $t->unsignedSmallInteger('capacity');             // people per slot
            $t->timestamps();
            $t->index(['facility_id', 'service_id', 'weekday']);
        });

        Schema::create('booking_closures', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('reason', 150)->nullable();
            $t->string('created_by')->nullable();
            $t->timestamps();
            $t->index(['facility_id', 'starts_on']);
        });

        Schema::create('booking_settings', function (Blueprint $t) {
            $t->string('facility_id')->primary();
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->unsignedSmallInteger('horizon_days')->default(30);
            $t->unsignedSmallInteger('min_notice_minutes')->default(120);
            $t->string('updated_by')->nullable();
            $t->timestamps();
        });

        Schema::create('slot_holds', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->ulid('service_id');                           // what is being booked
            $t->ulid('timetable_service_id')->nullable();     // the timetable it counts against
            $t->dateTime('slot_at');
            $t->string('reference', 40)->unique();            // the Hospify order reference
            $t->dateTime('expires_at');
            $t->timestamp('created_at')->nullable();
            $t->index(['facility_id', 'slot_at']);
        });

        Schema::table('appointments', function (Blueprint $t) {
            $t->dateTime('slot_at')->nullable()->after('preferred_time');
        });

        Schema::table('care_bookings', function (Blueprint $t) {
            $t->dateTime('slot_at')->nullable()->after('preferred_at');
            $t->ulid('timetable_service_id')->nullable()->after('service_id');
            $t->boolean('staff_alerted')->default(false);   // told the facility it's still unconfirmed, 2 hours before
            $t->boolean('reminded')->default(false);        // the patient's evening before reminder
        });
    }

    public function down(): void
    {
        Schema::table('care_bookings', fn (Blueprint $t) => $t->dropColumn(['slot_at', 'timetable_service_id', 'staff_alerted', 'reminded']));
        Schema::table('appointments', fn (Blueprint $t) => $t->dropColumn('slot_at'));
        Schema::dropIfExists('slot_holds');
        Schema::dropIfExists('booking_settings');
        Schema::dropIfExists('booking_closures');
        Schema::dropIfExists('booking_hours');
    }
};
