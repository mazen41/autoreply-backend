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
        // Ensure sequence_enrollments table exists first
        if (!Schema::hasTable('sequence_enrollments')) {
            if (Schema::hasTable('sequence_users')) {
                Schema::rename('sequence_users', 'sequence_enrollments');
            } else {
                // Create the table if it doesn't exist at all
                Schema::create('sequence_enrollments', function (Blueprint $table) {
                    $table->id();
                    $table->foreignId('sequence_id')->constrained('sequences')->onDelete('cascade');
                    $table->foreignId('conversation_id')->constrained('conversations')->onDelete('cascade');
                    $table->enum('status', ['active', 'completed', 'stopped', 'failed'])->default('active');
                    $table->integer('current_step')->default(1);
                    $table->timestamp('next_execution_at')->nullable();
                    $table->timestamp('completed_at')->nullable();
                    $table->timestamp('stopped_at')->nullable();
                    $table->string('stop_reason')->nullable();
                    $table->string('failed_reason')->nullable();
                    $table->timestamps();
                    
                    $table->index(['sequence_id', 'status']);
                    $table->index(['conversation_id', 'status']);
                    $table->index('next_execution_at');
                });
            }
        }

        Schema::create('sequence_step_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('sequences')->onDelete('cascade');
            $table->foreignId('sequence_enrollment_id')->constrained('sequence_enrollments')->onDelete('cascade');
            $table->foreignId('sequence_step_id')->constrained('sequence_steps')->onDelete('cascade');
            $table->enum('status', ['pending', 'processing', 'executed', 'failed', 'skipped'])->default('pending');
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->onDelete('set null');
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['sequence_enrollment_id', 'status']);
            $table->index('scheduled_at');
            $table->index('executed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sequence_step_executions');
    }
};
