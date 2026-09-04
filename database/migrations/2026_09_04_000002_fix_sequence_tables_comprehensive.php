<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Handle all possible table states
        $hasSequenceUsers = Schema::hasTable('sequence_users');
        $hasSequenceEnrollments = Schema::hasTable('sequence_enrollments');
        
        // Case 1: Both tables exist (shouldn't happen, but handle it)
        if ($hasSequenceUsers && $hasSequenceEnrollments) {
            Schema::dropIfExists('sequence_users');
        }
        // Case 2: Only sequence_users exists (rename it)
        elseif ($hasSequenceUsers && !$hasSequenceEnrollments) {
            Schema::rename('sequence_users', 'sequence_enrollments');
        }
        // Case 3: Neither exists (create fresh)
        elseif (!$hasSequenceUsers && !$hasSequenceEnrollments) {
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
        // Mark the rename migration as completed to skip it
        DB::table('migrations')->where('migration', '2026_09_02_000007_rename_sequence_users_to_enrollments')->update(['batch' => DB::raw('batch + 1')]);
        
        // Now ensure the tables have all required columns
        if (Schema::hasTable('sequence_enrollments')) {
            Schema::table('sequence_enrollments', function (Blueprint $table) {
                if (!Schema::hasColumn('sequence_enrollments', 'timezone')) {
                    $table->string('timezone')->default('UTC')->nullable();
                }
                if (!Schema::hasColumn('sequence_enrollments', 'business_hours')) {
                    $table->json('business_hours')->nullable();
                }
            });
        }

        // Add timezone and business_hours to sequences table if they don't exist
        if (Schema::hasTable('sequences')) {
            Schema::table('sequences', function (Blueprint $table) {
                if (!Schema::hasColumn('sequences', 'timezone')) {
                    $table->string('timezone')->default('UTC')->nullable();
                }
                if (!Schema::hasColumn('sequences', 'business_hours')) {
                    $table->json('business_hours')->nullable();
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Rollback - rename back if needed
        if (Schema::hasTable('sequence_enrollments') && !Schema::hasTable('sequence_users')) {
            Schema::rename('sequence_enrollments', 'sequence_users');
        }
        
        // Remove added columns
        if (Schema::hasTable('sequence_enrollments')) {
            Schema::table('sequence_enrollments', function (Blueprint $table) {
                if (Schema::hasColumn('sequence_enrollments', 'timezone')) {
                    $table->dropColumn('timezone');
                }
                if (Schema::hasColumn('sequence_enrollments', 'business_hours')) {
                    $table->dropColumn('business_hours');
                }
            });
        }

        // Remove columns from sequences table
        if (Schema::hasTable('sequences')) {
            Schema::table('sequences', function (Blueprint $table) {
                if (Schema::hasColumn('sequences', 'timezone')) {
                    $table->dropColumn('timezone');
                }
                if (Schema::hasColumn('sequences', 'business_hours')) {
                    $table->dropColumn('business_hours');
                }
            });
        }
    }
};
