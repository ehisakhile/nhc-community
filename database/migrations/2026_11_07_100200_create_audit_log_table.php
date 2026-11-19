<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add-only. No foreign keys, so the history outlives whatever it describes.
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 20);
            $table->string('actor_id', 64)->nullable();
            $table->string('facility_id')->nullable()->index();
            $table->string('patient_id', 26)->nullable()->index();
            $table->string('action', 60)->index();
            $table->string('target_type', 40)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->string('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
