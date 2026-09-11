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
        Schema::table('automation_workflows', function (Blueprint $table) {
            // Add business_id as nullable first
            $table->foreignId('business_id')->nullable()->after('user_id')->constrained('business_profiles')->onDelete('cascade');

            // Add other missing fields
            $table->text('description')->nullable()->after('name');
            $table->json('conditions')->nullable()->after('trigger_config');
            $table->timestamp('last_executed_at')->nullable()->after('executions_count');

            // Update index to include business_id
            // SQLite doesn't support indexes on nullable foreign keys that were added after table creation
            if (DB::getDriverName() !== 'sqlite') {
                $table->index(['business_id', 'active']);
            }
        });

        // Migrate existing workflows: copy user's primary business to business_id
        // This assumes each user has at least one business_profile
        if (DB::getDriverName() === 'sqlite') {
            // SQLite-compatible UPDATE syntax
            DB::statement("
                UPDATE automation_workflows
                SET business_id = (
                    SELECT id FROM business_profiles 
                    WHERE business_profiles.user_id = automation_workflows.user_id 
                    LIMIT 1
                )
                WHERE business_id IS NULL
            ");
        } else {
            // MySQL-style UPDATE with LEFT JOIN
            DB::statement("
                UPDATE automation_workflows aw
                LEFT JOIN business_profiles bp ON bp.user_id = aw.user_id
                SET aw.business_id = bp.id
                WHERE aw.business_id IS NULL
            ");
        }

        // Now make business_id non-nullable after migration
        // Skip for SQLite in tests where there might be no existing data
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('automation_workflows', function (Blueprint $table) {
                $table->foreignId('business_id')->nullable(false)->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn(['business_id', 'description', 'conditions', 'last_executed_at']);
            // SQLite doesn't support this index, so skip if it doesn't exist
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropIndex(['business_id', 'active']);
            }
        });
    }
};
