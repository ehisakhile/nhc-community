<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The catalogue. Written by `php artisan nhc:features:sync` from what the
        // modules declare; the Feature hub reads it.
        Schema::create('features', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('module', 60);
            $table->string('name');
            $table->text('description');
            $table->string('category', 60);
            $table->boolean('is_core')->default(false);
            $table->boolean('default_on')->default(false);
            $table->string('status', 20)->default('live');
            $table->json('depends_on')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // A hospital's own choice. No row: the feature's default applies.
        Schema::create('facility_features', function (Blueprint $table) {
            $table->string('facility_id');
            $table->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->string('feature_key', 60);
            $table->foreign('feature_key')->references('key')->on('features')->cascadeOnDelete();
            $table->boolean('enabled');
            $table->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->primary(['facility_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_features');
        Schema::dropIfExists('features');
    }
};
