<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sign-in and phone-check codes. Only a hash of the code is kept.
        Schema::create('one_time_codes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('purpose', 30);
            $table->string('channel', 10);
            $table->nullableUlidMorphs('subject');
            $table->text('payload')->nullable();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_time_codes');
    }
};
