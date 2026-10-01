<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ── Table Schema Updates ─────────────────────────────────────────────

        // Add bot_id to sequences table
        Schema::table('sequences', function (Blueprint $table) {
            $table->unsignedBigInteger('bot_id')->nullable()->after('business_id');
            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('set null');
        });

        // Add bot_id to automation_workflows table
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->unsignedBigInteger('bot_id')->nullable()->after('business_id');
            $table->foreign('bot_id')->references('id')->on('bots')->onDelete('set null');
        });

        // ── Performance Composite Indexes ────────────────────────────────────

        // Messages: composite index for conversation + direction + date queries
        Schema::table('messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'direction', 'created_at'], 'messages_conversation_direction_created_index');
        });

        // Sequence enrollments: composite index for conversation + status lookups
        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->index(['conversation_id', 'status'], 'sequence_enrollments_conversation_status_index');
        });

        // Conversations: index for agent assignment queries
        Schema::table('conversations', function (Blueprint $table) {
            $table->index(['assigned_agent_id', 'assigned_at'], 'conversations_assigned_agent_index');
        });

        // ── Primary Bot DB Partial Unique Constraint ────────────────────────
        // Ensure only one primary bot per channel at the database level
        DB::statement('CREATE UNIQUE INDEX bot_channels_channel_primary_unique ON bot_channels (channel_id) WHERE is_primary = true;');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop partial unique index
        DB::statement('DROP INDEX IF EXISTS bot_channels_channel_primary_unique;');

        // Drop performance indexes
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_assigned_agent_index');
        });

        Schema::table('sequence_enrollments', function (Blueprint $table) {
            $table->dropIndex('sequence_enrollments_conversation_status_index');
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_conversation_direction_created_index');
        });

        // Drop bot_id columns
        Schema::table('automation_workflows', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
            $table->dropColumn('bot_id');
        });

        Schema::table('sequences', function (Blueprint $table) {
            $table->dropForeign(['bot_id']);
            $table->dropColumn('bot_id');
        });
    }
};
