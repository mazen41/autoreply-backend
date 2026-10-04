<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop ALL indexes that reference columns we're about to drop.
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // MySQL: drop the FK first — its supporting composite unique
            // index (business_id, date) cannot be dropped while it backs
            // the FK (error 1553).
            Schema::table('ai_metrics', function (Blueprint $table) {
                try {
                    $table->dropForeign(['business_id']);
                } catch (\Exception $e) {
                    // Foreign key may not exist
                }
            });

            Schema::table('ai_metrics', function (Blueprint $table) {
                foreach (['ai_metrics_business_id_date_unique', 'ai_metrics_date_unique', 'ai_metrics_date_index'] as $indexName) {
                    try {
                        $table->dropIndex($indexName);
                    } catch (\Exception $e) {
                        // Index may not exist
                    }
                }
            });
        } else {
            // SQLite: indexes must be dropped before the columns they reference.
            Schema::table('ai_metrics', function (Blueprint $table) {
                $allIndexes = \DB::select("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='ai_metrics'");
                foreach ($allIndexes as $index) {
                    // Skip auto-indexes (SQLite internal)
                    if (str_starts_with($index->name, 'sqlite_autoindex_')) {
                        continue;
                    }
                    try {
                        $table->dropIndex($index->name);
                    } catch (\Exception $e) {
                        // Index may already be dropped or may not exist
                    }
                }
                // Drop foreign key on business_id if it exists
                if (Schema::hasColumn('ai_metrics', 'business_id')) {
                    try {
                        $table->dropForeign(['business_id']);
                    } catch (\Exception $e) {
                        // Foreign key may not exist
                    }
                }
            });
        }

        // Drop old columns if they exist
        Schema::table('ai_metrics', function (Blueprint $table) {
            $columnsToDrop = [
                'business_id', 'date', 'total_ai_messages', 'successful_ai_messages',
                'escalated_messages', 'avg_confidence_score', 'positive_feedback',
                'negative_feedback', 'success_rate',
            ];
            foreach ($columnsToDrop as $column) {
                if (Schema::hasColumn('ai_metrics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // Add new columns if they don't exist
        Schema::table('ai_metrics', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_metrics', 'business_profile_id')) {
                $table->unsignedBigInteger('business_profile_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('ai_metrics', 'bot_id')) {
                $table->unsignedBigInteger('bot_id')->nullable()->after('business_profile_id');
            }
            if (!Schema::hasColumn('ai_metrics', 'provider')) {
                $table->string('provider')->nullable()->after('bot_id');
            }
            if (!Schema::hasColumn('ai_metrics', 'model')) {
                $table->string('model')->nullable()->after('provider');
            }
            if (!Schema::hasColumn('ai_metrics', 'prompt_tokens')) {
                $table->unsignedInteger('prompt_tokens')->default(0)->after('model');
            }
            if (!Schema::hasColumn('ai_metrics', 'completion_tokens')) {
                $table->unsignedInteger('completion_tokens')->default(0)->after('prompt_tokens');
            }
            if (!Schema::hasColumn('ai_metrics', 'total_tokens')) {
                $table->unsignedInteger('total_tokens')->default(0)->after('completion_tokens');
            }
            if (!Schema::hasColumn('ai_metrics', 'estimated_cost')) {
                $table->decimal('estimated_cost', 8, 6)->default(0)->after('total_tokens');
            }
            if (!Schema::hasColumn('ai_metrics', 'response_time_ms')) {
                $table->unsignedInteger('response_time_ms')->nullable()->after('estimated_cost');
            }
            if (!Schema::hasColumn('ai_metrics', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (!Schema::hasColumn('ai_metrics', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }

            // Add indexes if they don't exist
            if (!Schema::hasIndex('ai_metrics', 'ai_metrics_business_created_index')) {
                $table->index(['business_profile_id', 'created_at'], 'ai_metrics_business_created_index');
            }
            if (!Schema::hasIndex('ai_metrics', 'ai_metrics_bot_created_index')) {
                $table->index(['bot_id', 'created_at'], 'ai_metrics_bot_created_index');
            }
            if (!Schema::hasIndex('ai_metrics', 'ai_metrics_provider_model_index')) {
                $table->index(['provider', 'model'], 'ai_metrics_provider_model_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_metrics', function (Blueprint $table) {
            $table->dropIndex('ai_metrics_business_created_index');
            $table->dropIndex('ai_metrics_bot_created_index');
            $table->dropIndex('ai_metrics_provider_model_index');

            $table->dropColumn([
                'business_profile_id', 'bot_id', 'provider', 'model',
                'prompt_tokens', 'completion_tokens', 'total_tokens',
                'estimated_cost', 'response_time_ms', 'created_at', 'updated_at',
            ]);

            // Restore old columns
            $table->unsignedBigInteger('business_id')->nullable();
            $table->date('date')->nullable();
            $table->unsignedInteger('total_ai_messages')->default(0);
            $table->unsignedInteger('successful_ai_messages')->default(0);
            $table->unsignedInteger('escalated_messages')->default(0);
            $table->decimal('avg_confidence_score', 5, 4)->nullable();
            $table->unsignedInteger('positive_feedback')->default(0);
            $table->unsignedInteger('negative_feedback')->default(0);
            $table->decimal('success_rate', 5, 4)->nullable();
        });
    }
};
