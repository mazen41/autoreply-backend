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
        // Conversation tags for auto-tagging
        Schema::create('conversation_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->onDelete('cascade');
            $table->string('tag');
            $table->string('intent')->nullable(); // AI-detected intent
            $table->decimal('confidence', 5, 2)->nullable(); // AI confidence score
            $table->enum('source', ['ai', 'manual'])->default('ai');
            $table->timestamps();
            
            $table->index(['conversation_id', 'tag']);
            $table->index('intent');
        });

        // Message corrections for learning
        Schema::create('message_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('original_message_id')->constrained('messages')->onDelete('cascade');
            $table->text('ai_draft');
            $table->text('human_correction');
            $table->boolean('approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->string('learning_type')->nullable(); // 'faq', 'tone', 'knowledge'
            $table->timestamps();
            
            $table->index('approved');
        });

        // Conversation escalation flags
        Schema::table('conversations', function (Blueprint $table) {
            $table->boolean('requires_human')->default(false)->after('status');
            $table->timestamp('escalated_at')->nullable()->after('requires_human');
            $table->string('escalation_reason')->nullable()->after('escalated_at');
            $table->boolean('escalation_notified')->default(false)->after('escalation_reason');
        });

        // Automation workflows
        Schema::create('automation_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->json('trigger_config'); // {type, conditions}
            $table->json('actions_config'); // Array of action objects
            $table->integer('executions_count')->default(0);
            $table->timestamps();
            
            $table->index(['user_id', 'active']);
        });

        // Proactive campaigns
        Schema::create('proactive_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('message');
            $table->dateTime('scheduled_for');
            $table->dateTime('sent_at')->nullable();
            $table->enum('status', ['scheduled', 'sent', 'cancelled'])->default('scheduled');
            $table->json('segment_config'); // {conditions for targeting}
            $table->integer('recipients_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->timestamps();
            
            $table->index(['user_id', 'status']);
            $table->index('scheduled_for');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proactive_campaigns');
        Schema::dropIfExists('automation_workflows');
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['requires_human', 'escalated_at', 'escalation_reason', 'escalation_notified']);
        });
        Schema::dropIfExists('message_corrections');
        Schema::dropIfExists('conversation_tags');
    }
};