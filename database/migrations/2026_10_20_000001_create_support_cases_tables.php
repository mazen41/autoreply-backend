<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained('business_profiles')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('commerce_order_id')->nullable()->constrained('commerce_orders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('case_number', 32)->unique();
            $table->string('subject', 191);
            $table->text('description')->nullable();
            $table->string('status', 24)->default('open');
            $table->string('priority', 24)->default('normal');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status', 'created_at']);
            $table->index(['business_id', 'assigned_to', 'status']);
        });

        Schema::create('support_case_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['support_case_id', 'created_at']);
        });

        Schema::create('support_case_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_case_id')->constrained('support_cases')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['support_case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_case_events');
        Schema::dropIfExists('support_case_comments');
        Schema::dropIfExists('support_cases');
    }
};
