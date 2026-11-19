<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A patient's conversations with a facility's care team. Message text is encrypted. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_threads', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('facility_id');
            $t->foreign('facility_id')->references('id')->on('tenants')->cascadeOnDelete();
            $t->ulid('patient_id');
            $t->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
            $t->string('subject', 150);
            $t->string('status', 10)->default('open');  // open | closed
            $t->unsignedSmallInteger('patient_unread')->default(0);
            $t->unsignedSmallInteger('staff_unread')->default(0);
            $t->dateTime('last_message_at');
            $t->timestamps();
            $t->index(['facility_id', 'status', 'last_message_at']);
            $t->index(['patient_id', 'last_message_at']);
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('thread_id');
            $t->foreign('thread_id')->references('id')->on('message_threads')->cascadeOnDelete();
            $t->string('sender', 10);                    // patient | staff
            $t->unsignedBigInteger('staff_user_id')->nullable();
            $t->string('sender_name', 150);
            $t->text('body');                            // encrypted
            $t->timestamp('created_at');
            $t->index(['thread_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('message_threads');
    }
};
