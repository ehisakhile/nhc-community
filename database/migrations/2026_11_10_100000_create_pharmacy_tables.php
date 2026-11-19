<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Pharmacy module: a pharmacy's shop settings, its catalogue (NAFDAC
 * Greenbook products with its own prices and stock) and the orders that come
 * from the Hospify app. Central, because customers search across pharmacies;
 * each row is locked to its pharmacy (BelongsToFacility) and only
 * App\Modules\Pharmacy\Shop reads across them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pharmacy_shops', function (Blueprint $t) {
            $t->string('facility_id')->primary();
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->boolean('accepting_orders')->default(false);
            $t->boolean('pickup_enabled')->default(true);
            $t->boolean('delivery_enabled')->default(false);
            $t->unsignedInteger('delivery_fee_kobo')->default(0);
            $t->decimal('delivery_radius_km', 5, 1)->default(5);
            $t->unsignedInteger('min_order_kobo')->default(0);
            $t->string('opening_hours')->nullable();
            $t->string('address', 500)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('pharmacy_listings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->unsignedInteger('greenbook_id');
            $t->string('nafdac', 50);
            $t->string('name');
            $t->string('strength')->nullable();
            $t->string('form', 100)->nullable();
            $t->string('pack_size')->nullable();
            $t->string('ingredient')->nullable();
            $t->string('registration', 20);                 // registered | expired, from Greenbook
            $t->date('registration_expires')->nullable();
            $t->unsignedInteger('price_kobo');
            $t->unsignedInteger('stock_qty')->nullable();    // null: not counted
            $t->unsignedInteger('reorder_level')->nullable();
            $t->boolean('in_stock')->default(true);
            $t->boolean('over_the_counter')->default(false); // the pharmacist's call
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['facility_id', 'greenbook_id']);
            $t->index(['facility_id', 'name']);
        });

        Schema::create('pharmacy_orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->restrictOnDelete();
            $t->string('hospify_reference', 40)->unique();   // Hospify's order reference (it took the payment)
            $t->string('customer_ref', 64);                  // Hospify's reference for the customer, never their user ID
            $t->string('customer_name');
            $t->string('contact_phone', 30)->nullable();
            $t->string('fulfilment', 10);                    // pickup | delivery
            $t->string('delivery_address', 500)->nullable();
            $t->decimal('delivery_latitude', 10, 7)->nullable();
            $t->decimal('delivery_longitude', 10, 7)->nullable();
            $t->string('note', 500)->nullable();
            $t->json('items');
            $t->unsignedInteger('subtotal_kobo');
            $t->unsignedInteger('delivery_fee_kobo')->default(0);
            $t->unsignedInteger('total_kobo');
            $t->unsignedInteger('commission_kobo')->default(0);
            $t->string('status', 20)->index();              // paid | accepted | ready | out_for_delivery | completed | rejected | cancelled
            $t->string('reason', 500)->nullable();
            $t->json('history');
            $t->timestamps();
            $t->index(['facility_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_orders');
        Schema::dropIfExists('pharmacy_listings');
        Schema::dropIfExists('pharmacy_shops');
    }
};
