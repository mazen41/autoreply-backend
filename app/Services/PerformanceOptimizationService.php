<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PerformanceOptimizationService
{
    /**
     * Add database indexes for performance
     */
    public function addPerformanceIndexes(): void
    {
        try {
            // Messages table indexes
            DB::statement('CREATE INDEX IF NOT EXISTS idx_messages_conversation_created ON messages(conversation_id, created_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_messages_direction_status ON messages(direction, status)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_messages_is_ai ON messages(is_ai, created_at)');

            // Conversations table indexes
            DB::statement('CREATE INDEX IF NOT EXISTS idx_conversations_channel_last_message ON conversations(channel_id, last_message_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_conversations_status_requires_human ON conversations(status, requires_human)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_conversations_assigned_agent ON conversations(assigned_agent_id, last_message_at)');

            // Message feedback indexes
            DB::statement('CREATE INDEX IF NOT EXISTS idx_message_feedbacks_created ON message_feedbacks(created_at)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_message_feedbacks_feedback ON message_feedbacks(feedback)');

            // Analytics indexes
            DB::statement('CREATE INDEX IF NOT EXISTS idx_analytics_daily_business_date ON analytics_daily(business_id, date)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_ai_metrics_business_date ON ai_metrics(business_id, date)');

            // CSAT ratings indexes
            DB::statement('CREATE INDEX IF NOT EXISTS idx_csat_ratings_business_rated ON csat_ratings(business_id, rated_at)');

            // Campaigns indexes
            DB::statement('CREATE INDEX IF NOT EXISTS idx_campaigns_business_status ON campaigns(business_id, status)');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_campaigns_scheduled ON campaigns(scheduled_at)');

            // Webhooks indexes
            DB::statement('CREATE INDEX IF NOT EXISTS idx_webhooks_business_active ON webhooks(business_id, is_active)');

            Log::info('Performance indexes added successfully');
        } catch (\Exception $e) {
            Log::error('Failed to add performance indexes', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Cache frequently accessed data
     */
    public function cacheBusinessData(int $businessId): void
    {
        $cacheKey = "business_data_{$businessId}";
        
        Cache::remember($cacheKey, 3600, function () use ($businessId) {
            $business = \App\Models\BusinessProfile::with([
                'channels',
                'teamMembers.user',
                'businessHours',
                'autoMessages',
            ])->find($businessId);

            return $business;
        });
    }

    /**
     * Cache user subscription data
     */
    public function cacheSubscriptionData(int $userId): void
    {
        $cacheKey = "user_subscription_{$userId}";
        
        Cache::remember($cacheKey, 1800, function () use ($userId) {
            return \App\Models\Subscription::with('package')
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->first();
        });
    }

    /**
     * Clear cache for business data
     */
    public function clearBusinessCache(int $businessId): void
    {
        Cache::forget("business_data_{$businessId}");
    }

    /**
     * Clear cache for user subscription
     */
    public function clearSubscriptionCache(int $userId): void
    {
        Cache::forget("user_subscription_{$userId}");
    }

    /**
     * Optimize database tables
     */
    public function optimizeTables(): void
    {
        try {
            $tables = [
                'messages',
                'conversations',
                'users',
                'channels',
                'business_profiles',
                'message_feedbacks',
                'analytics_daily',
                'ai_metrics',
                'notifications',
            ];

            foreach ($tables as $table) {
                DB::statement("OPTIMIZE TABLE {$table}");
            }

            Log::info('Database tables optimized');
        } catch (\Exception $e) {
            Log::error('Failed to optimize tables', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get query performance metrics
     */
    public function getQueryPerformanceMetrics(): array
    {
        try {
            $slowQueries = DB::select("
                SELECT query_time, lock_time, rows_sent, rows_examined, sql_text
                FROM mysql.slow_log
                ORDER BY query_time DESC
                LIMIT 10
            ");

            return [
                'slow_queries' => $slowQueries,
                'total_queries' => count($slowQueries),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get query performance metrics', ['error' => $e->getMessage()]);
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Enable query logging for debugging
     */
    public function enableQueryLogging(): void
    {
        DB::enableQueryLog();
    }

    /**
     * Get executed queries
     */
    public function getExecutedQueries(): array
    {
        return DB::getQueryLog();
    }

    /**
     * Clear query log
     */
    public function clearQueryLog(): void
    {
        DB::flushQueryLog();
    }
}
