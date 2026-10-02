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

        // Add bot_id to sequences table (if not already exists)
        if (!Schema::hasColumn('sequences', 'bot_id')) {
            Schema::table('sequences', function (Blueprint $table) {
                $table->unsignedBigInteger('bot_id')->nullable()->after('business_id');
                $table->foreign('bot_id')->references('id')->on('bots')->onDelete('set null');
            });
        }

        // Add bot_id to automation_workflows table (if not already exists)
        if (!Schema::hasColumn('automation_workflows', 'bot_id')) {
            Schema::table('automation_workflows', function (Blueprint $table) {
                $table->unsignedBigInteger('bot_id')->nullable()->after('business_id');
                $table->foreign('bot_id')->references('id')->on('bots')->onDelete('set null');
            });
        }

        // ── Performance Composite Indexes ────────────────────────────────────

        // Messages: composite index for conversation + direction + date queries
        if (!Schema::hasIndex('messages', 'messages_conversation_direction_created_index')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->index(['conversation_id', 'direction', 'created_at'], 'messages_conversation_direction_created_index');
            });
        }

        // Sequence enrollments: composite index for conversation + status lookups
        if (!Schema::hasIndex('sequence_enrollments', 'sequence_enrollments_conversation_status_index')) {
            Schema::table('sequence_enrollments', function (Blueprint $table) {
                $table->index(['conversation_id', 'status'], 'sequence_enrollments_conversation_status_index');
            });
        }

        // Conversations: index for agent assignment queries
        if (!Schema::hasIndex('conversations', 'conversations_assigned_agent_index')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->index(['assigned_agent_id', 'assigned_at'], 'conversations_assigned_agent_index');
            });
        }

        // ── Primary Bot DB Constraint (MySQL-compatible) ─────────────────────
        // MySQL does not support partial unique indexes (WHERE clause).
        // Use a BEFORE INSERT trigger to enforce single primary bot per channel.
        DB::unprepared('DROP TRIGGER IF EXISTS bot_channels_single_primary_insert;');
        DB::unprepared('
            CREATE TRIGGER bot_channels_single_primary_insert
            BEFORE INSERT ON bot_channels
            FOR EACH ROW
            BEGIN
                IF NEW.is_primary = true THEN
                    IF EXISTS (
                        SELECT 1 FROM bot_channels
                        WHERE channel_id = NEW.channel_id
                        AND is_primary = true
                        AND bot_id != NEW.bot_id
                    ) THEN
                        SIGNAL SQLSTATE "45000"
                        SET MESSAGE_TEXT = "Only one primary bot is allowed per channel";
                    END IF;
                END IF;
            END
        ');

        DB::unprepared('DROP TRIGGER IF EXISTS bot_channels_single_primary_update;');
        DB::unprepared('
            CREATE TRIGGER bot_channels_single_primary_update
            BEFORE UPDATE ON bot_channels
            FOR EACH ROW
            BEGIN
                IF NEW.is_primary = true THEN
                    IF EXISTS (
                        SELECT 1 FROM bot_channels
                        WHERE channel_id = NEW.channel_id
                        AND is_primary = true
                        AND bot_id != NEW.bot_id
                    ) THEN
                        SIGNAL SQLSTATE "45000"
                        SET MESSAGE_TEXT = "Only one primary bot is allowed per channel";
                    END IF;
                END IF;
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop triggers
        DB::unprepared('DROP TRIGGER IF EXISTS bot_channels_single_primary_insert;');
        DB::unprepared('DROP TRIGGER IF EXISTS bot_channels_single_primary_update;');

        // Drop performance indexes
        if (Schema::hasIndex('conversations', 'conversations_assigned_agent_index')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->dropIndex('conversations_assigned_agent_index');
            });
        }

        if (Schema::hasIndex('sequence_enrollments', 'sequence_enrollments_conversation_status_index')) {
            Schema::table('sequence_enrollments', function (Blueprint $table) {
                $table->dropIndex('sequence_enrollments_conversation_status_index');
            });
        }

        if (Schema::hasIndex('messages', 'messages_conversation_direction_created_index')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropIndex('messages_conversation_direction_created_index');
            });
        }

        // Drop bot_id columns
        if (Schema::hasColumn('automation_workflows', 'bot_id')) {
            Schema::table('automation_workflows', function (Blueprint $table) {
                $table->dropForeign(['bot_id']);
                $table->dropColumn('bot_id');
            });
        }

        if (Schema::hasColumn('sequences', 'bot_id')) {
            Schema::table('sequences', function (Blueprint $table) {
                $table->dropForeign(['bot_id']);
                $table->dropColumn('bot_id');
            });
        }
    }
};
