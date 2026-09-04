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
        // First, check if sequence_enrollments table exists, if not, create it from sequence_users
        if (!Schema::hasTable('sequence_enrollments') && Schema::hasTable('sequence_users')) {
            Schema::rename('sequence_users', 'sequence_enrollments');
        }
        
        // If sequence_enrollments still doesn't exist, create it fresh
        if (!Schema::hasTable('sequence_enrollments')) {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback - rename back to sequence_users if needed
        if (Schema::hasTable('sequence_enrollments') && !Schema::hasTable('sequence_users')) {
            Schema::rename('sequence_enrollments', 'sequence_users');
        }
    }
};
