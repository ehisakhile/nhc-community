<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Care people book and pay for on Hospify: lab tests, scans and specialist
 * consultations (app/Modules/Care). Chat between a customer and the facility
 * about an order or booking. Doctor's letters on pharmacy orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_services', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->string('kind', 16);                          // lab_test | scan | consultation
            $t->string('name', 150);                         // "Full blood count", "Pelvic ultrasound", "Cardiology consultation"
            $t->string('category', 80)->nullable();          // Haematology, Ultrasound, Cardiology...
            $t->string('description', 1000)->nullable();
            $t->string('preparation', 500)->nullable();      // "Fast for 8 hours"
            $t->string('specialist_name', 150)->nullable();  // consultations: "Dr Ada Okafor"
            $t->string('specialist_title', 150)->nullable(); // "Consultant cardiologist"
            $t->unsignedInteger('price_kobo');
            $t->unsignedSmallInteger('duration_minutes')->nullable();
            $t->unsignedSmallInteger('turnaround_hours')->nullable(); // results in
            $t->boolean('requires_referral')->default(false); // a doctor's letter or request form
            $t->boolean('walk_in')->default(true);            // at the facility
            $t->boolean('home')->default(false);               // lab: sample taken at home
            $t->unsignedInteger('home_fee_kobo')->default(0);
            $t->boolean('video')->default(false);              // consultation by video call
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index(['facility_id', 'kind', 'active']);
        });

        Schema::create('care_bookings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->restrictOnDelete();
            $t->string('hospify_reference', 40)->unique();
            $t->string('customer_ref', 64);
            $t->string('customer_name');
            $t->string('contact_phone', 30)->nullable();
            $t->string('kind', 16);
            $t->ulid('service_id')->nullable();
            $t->json('service');                             // what was booked, as it was then
            $t->string('mode', 10);                          // walk_in | home | video
            $t->dateTime('preferred_at');
            $t->dateTime('scheduled_at')->nullable();
            $t->string('address', 500)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('note', 500)->nullable();
            $t->json('attachments')->nullable();             // [{name, mime}] (Hospify keeps the files)
            $t->unsignedInteger('price_kobo');
            $t->unsignedInteger('fee_kobo')->default(0);
            $t->unsignedInteger('total_kobo');
            $t->unsignedInteger('commission_kobo')->default(0);
            $t->string('status', 20)->index();              // paid | accepted | ready | completed | rejected | cancelled
            $t->string('reason', 500)->nullable();
            $t->string('video_link', 500)->nullable();
            $t->json('history');
            $t->timestamps();
            $t->index(['facility_id', 'status']);
        });

        Schema::create('order_messages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->string('reference', 40)->index();            // the Hospify order or booking reference
            $t->string('sender', 10);                        // customer | facility
            $t->string('sender_name', 150);
            $t->text('body');                                // encrypted
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at');
            $t->index(['facility_id', 'reference', 'created_at']);
        });

        Schema::table('pharmacy_orders', function (Blueprint $t) {
            $t->json('attachments')->nullable()->after('note');  // a doctor's letter, for prescription medicines
        });
    }

    public function down(): void
    {
        Schema::table('pharmacy_orders', fn (Blueprint $t) => $t->dropColumn('attachments'));
        Schema::dropIfExists('order_messages');
        Schema::dropIfExists('care_bookings');
        Schema::dropIfExists('care_services');
    }
};
