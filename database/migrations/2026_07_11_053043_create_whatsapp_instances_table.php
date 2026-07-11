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
        Schema::create('whatsapp_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('instance_name')->unique();
            $table->string('phone_number')->nullable();
            $table->string('profile_name')->nullable();
            $table->string('profile_picture_url')->nullable();
            $table->enum('status', ['pending', 'connecting', 'connected', 'disconnected', 'error'])->default('pending');
            $table->string('evolution_api_token')->nullable();
            $table->string('evolution_instance_id')->nullable();
            $table->json('webhook_url')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_instances');
    }
};
