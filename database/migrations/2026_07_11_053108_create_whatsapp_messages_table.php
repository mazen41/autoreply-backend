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
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_instance_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('message_id')->nullable();
            $table->string('remote_message_id')->nullable();
            $table->enum('direction', ['incoming', 'outgoing'])->default('incoming');
            $table->string('from_phone')->nullable();
            $table->string('from_name')->nullable();
            $table->string('to_phone')->nullable();
            $table->text('body')->nullable();
            $table->string('message_type')->default('text');
            $table->json('media')->nullable();
            $table->json('metadata')->nullable();
            $table->enum('status', ['pending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
