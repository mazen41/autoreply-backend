<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CreatePerformanceIndexes extends Command
{
    protected $signature = 'performance:create-indexes';
    protected $description = 'Create database indexes for performance optimization';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->info('Creating performance indexes...');

        $indexes = [
            // Conversations table indexes
            'conversations' => [
                'idx_conversations_business_id' => 'business_id',
                'idx_conversations_channel_id' => 'channel_id',
                'idx_conversations_sender_id' => 'sender_id',
                'idx_conversations_status' => 'status',
                'idx_conversations_ai_replied' => 'ai_replied',
                'idx_conversations_created_at' => 'created_at',
                'idx_conversations_business_status' => ['business_id', 'status'],
                'idx_conversations_channel_created' => ['channel_id', 'created_at'],
            ],
            
            // Messages table indexes
            'messages' => [
                'idx_messages_conversation_id' => 'conversation_id',
                'idx_messages_sender_type' => 'sender_type',
                'idx_messages_created_at' => 'created_at',
                'idx_messages_conversation_created' => ['conversation_id', 'created_at'],
            ],
            
            // Channels table indexes
            'channels' => [
                'idx_channels_business_id' => 'business_id',
                'idx_channels_type' => 'type',
                'idx_channels_is_active' => 'is_active',
                'idx_channels_business_type' => ['business_id', 'type'],
            ],
            
            // Business profiles table indexes
            'business_profiles' => [
                'idx_business_profiles_user_id' => 'user_id',
                'idx_business_profiles_plan' => 'plan',
                'idx_business_profiles_status' => 'status',
            ],
            
            // Subscriptions table indexes
            'subscriptions' => [
                'idx_subscriptions_business_id' => 'business_id',
                'idx_subscriptions_status' => 'status',
                'idx_subscriptions_ends_at' => 'ends_at',
            ],
            
            // Analytics data table indexes
            'analytics_data' => [
                'idx_analytics_business_id' => 'business_id',
                'idx_analytics_date' => 'date',
                'idx_analytics_type' => 'type',
                'idx_analytics_business_date' => ['business_id', 'date'],
            ],
            
            // Web chat sessions table indexes
            'web_chat_sessions' => [
                'idx_web_chat_business_id' => 'business_id',
                'idx_web_chat_session_id' => 'session_id',
                'idx_web_chat_status' => 'status',
                'idx_web_chat_created_at' => 'created_at',
            ],
            
            // AI actions table indexes
            'ai_actions' => [
                'idx_ai_actions_business_id' => 'business_id',
                'idx_ai_actions_type' => 'action_type',
                'idx_ai_actions_status' => 'status',
                'idx_ai_actions_created_at' => 'created_at',
                'idx_ai_actions_business_created' => ['business_id', 'created_at'],
            ],
        ];

        foreach ($indexes as $table => $tableIndexes) {
            if (!Schema::hasTable($table)) {
                $this->warn("Table {$table} does not exist, skipping...");
                continue;
            }

            foreach ($tableIndexes as $indexName => $columns) {
                try {
                    $this->createIndex($table, $indexName, $columns);
                    $this->info("Created index {$indexName} on table {$table}");
                } catch (\Exception $e) {
                    $this->warn("Failed to create index {$indexName}: {$e->getMessage()}");
                }
            }
        }

        $this->info('Performance indexes creation completed.');
        return Command::SUCCESS;
    }

    private function createIndex(string $table, string $indexName, $columns)
    {
        $columns = is_array($columns) ? $columns : [$columns];
        
        // Check if index already exists
        $indexExists = DB::select(
            "SELECT COUNT(*) as count FROM information_schema.statistics 
             WHERE table_schema = DATABASE() 
             AND table_name = ? 
             AND index_name = ?",
            [$table, $indexName]
        );

        if ($indexExists[0]->count > 0) {
            $this->warn("Index {$indexName} already exists on table {$table}");
            return;
        }

        // Create the index
        $columnString = implode(', ', $columns);
        DB::statement("CREATE INDEX {$indexName} ON {$table} ({$columnString})");
    }
}