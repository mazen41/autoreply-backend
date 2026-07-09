<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('business_id')->nullable();
            $table->foreign('business_id')->references('id')->on('business_profiles')->onDelete('cascade');
            $table->string('sender_id');   // Facebook/Instagram user ID
            $table->string('sender_name')->nullable();
            $table->string('status')->default('open'); // open, closed
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            // One conversation per sender per channel
            $table->unique(['channel_id', 'sender_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
