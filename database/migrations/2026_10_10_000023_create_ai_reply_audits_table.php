<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_reply_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->unique()->constrained('messages')->cascadeOnDelete();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('bot_id')->nullable()->constrained('bots')->nullOnDelete();
            $table->foreignId('commerce_connection_id')->nullable()->constrained('channels')->nullOnDelete();
            $table->string('agent_version')->nullable();
            $table->json('knowledge_file_ids')->nullable();
            $table->json('knowledge_chunk_ids')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->decimal('confidence', 6, 5)->nullable();
            $table->boolean('escalation_decision')->default(false);
            $table->string('escalation_reason')->nullable();
            $table->string('consent_basis')->default('service_reply_to_customer_inbound');
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_reply_audits');
    }
};
