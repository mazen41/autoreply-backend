<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop foreign key constraints first before dropping columns
        Schema::table('ai_metrics', function (Blueprint $table) {
            // Drop foreign key on business_id if it exists
            if (Schema::hasColumn('ai_metrics', 'business_id')) {
                $table->dropForeign(['business_id']);
            }
        });

        Schema::table('ai_metrics', function (Blueprint $table) {
            // Drop old columns that are being replaced
            if (Schema::hasColumn('ai_metrics', 'business_id')) {
                $table->dropColumn('business_id');
            }
            if (Schema::hasColumn('ai_metrics', 'date')) {
                $table->dropColumn('date');
            }
            if (Schema::hasColumn('ai_metrics', 'total_ai_messages')) {
                $table->dropColumn('total_ai_messages');
            }
            if (Schema::hasColumn('ai_metrics', 'successful_ai_messages')) {
                $table->dropColumn('successful_ai_messages');
            }
            if (Schema::hasColumn('ai_metrics', 'escalated_messages')) {
                $table->dropColumn('escalated_messages');
            }
            if (Schema::hasColumn('ai_metrics', 'avg_confidence_score')) {
                $table->dropColumn('avg_confidence_score');
            }
            if (Schema::hasColumn('ai_metrics', 'positive_feedback')) {
                $table->dropColumn('positive_feedback');
            }
            if (Schema::hasColumn('ai_metrics', 'negative_feedback')) {
                $table->dropColumn('negative_feedback');
            }
            if (Schema::hasColumn('ai_metrics', 'success_rate')) {
                $table->dropColumn('success_rate');
            }

            // Add new columns for detailed token tracking
            $table->unsignedBigInteger('business_profile_id')->nullable()->after('id');
            $table->unsignedBigInteger('bot_id')->nullable()->after('business_profile_id');
            $table->string('provider')->nullable()->after('bot_id');
            $table->string('model')->nullable()->after('provider');
            $table->unsignedInteger('prompt_tokens')->default(0)->after('model');
            $table->unsignedInteger('completion_tokens')->default(0)->after('prompt_tokens');
            $table->unsignedInteger('total_tokens')->default(0)->after('completion_tokens');
            $table->decimal('estimated_cost', 8, 6)->default(0)->after('total_tokens');
            $table->unsignedInteger('response_time_ms')->nullable()->after('estimated_cost');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            // Indexes
            $table->index(['business_profile_id', 'created_at'], 'ai_metrics_business_created_index');
            $table->index(['bot_id', 'created_at'], 'ai_metrics_bot_created_index');
            $table->index(['provider', 'model'], 'ai_metrics_provider_model_index');
        });
    }

    public function down(): void
    {
        Schema::table('ai_metrics', function (Blueprint $table) {
            $table->dropIndex('ai_metrics_business_created_index');
            $table->dropIndex('ai_metrics_bot_created_index');
            $table->dropIndex('ai_metrics_provider_model_index');

            $table->dropColumn([
                'business_profile_id',
                'bot_id',
                'provider',
                'model',
                'prompt_tokens',
                'completion_tokens',
                'total_tokens',
                'estimated_cost',
                'response_time_ms',
                'created_at',
                'updated_at',
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
