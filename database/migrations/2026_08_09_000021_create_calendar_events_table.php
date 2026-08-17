<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->onDelete('cascade');
            $table->foreignId('conversation_id')->nullable()->constrained()->onDelete('set null');
            $table->string('title');
            $table->text('description')->nullable();
            // Explicit nullable(false) defaults avoid MySQL strict-mode "Invalid default
            // value" errors that occur when a table has two TIMESTAMP columns and MySQL
            // tries to auto-apply a zero-date default to the second one.
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('google_event_id')->nullable();
            $table->string('status')->default('confirmed'); // confirmed, cancelled, pending
            $table->json('attendees')->nullable();
            $table->timestamps();
            
            $table->index(['business_id', 'start_time']);
            $table->index('google_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};