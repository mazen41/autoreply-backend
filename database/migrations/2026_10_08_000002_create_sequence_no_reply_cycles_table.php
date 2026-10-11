<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequence_no_reply_cycles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('cycle')->default(0);
            $table->unsignedBigInteger('ai_reply_message_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['sequence_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequence_no_reply_cycles');
    }
};
