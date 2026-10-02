<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename whatsapp_message_id -> platform_message_id on product_message_maps
 * for channel neutrality (already applied to the create migration for fresh
 * installs). This migration handles existing production tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('product_message_maps', 'whatsapp_message_id')) {
            // Already migrated (platform_message_id exists)
            $this->ensureIndexExists();
            return;
        }

        // SQLite: rename works without index juggling
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('product_message_maps', function (Blueprint $table) {
                $table->renameColumn('whatsapp_message_id', 'platform_message_id');
            });
        } else {
            // MySQL: drop FK constraints + index, rename, recreate FK + index
            $this->dropFksAndIndex();

            Schema::table('product_message_maps', function (Blueprint $table) {
                $table->renameColumn('whatsapp_message_id', 'platform_message_id');
            });

            $this->recreateFksAndIndex();
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('product_message_maps', 'platform_message_id')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            Schema::table('product_message_maps', function (Blueprint $table) {
                $table->renameColumn('platform_message_id', 'whatsapp_message_id');
            });
        } else {
            $this->dropFksAndIndex();

            Schema::table('product_message_maps', function (Blueprint $table) {
                $table->renameColumn('platform_message_id', 'whatsapp_message_id');
            });

            $this->recreateFksAndIndex();
        }
    }

    private function dropFksAndIndex(): void
    {
        // Get foreign key constraint names that reference other tables
        $conn = Schema::getConnection();
        $dbName = $conn->getDatabaseName();
        $constraints = $conn->select(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS " .
            "WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$dbName, 'product_message_maps']
        );
        $fkNames = array_column($constraints, 'CONSTRAINT_NAME');

        Schema::table('product_message_maps', function (Blueprint $table) use ($fkNames) {
            foreach ($fkNames as $fk) {
                try {
                    $table->dropForeign($fk);
                } catch (\Exception $e) { /* ignore */ }
            }
            try {
                $table->dropUnique('product_message_maps_conv_msg_unique');
            } catch (\Exception $e) { /* ignore */ }
        });
    }

    private function recreateFksAndIndex(): void
    {
        Schema::table('product_message_maps', function (Blueprint $table) {
            try {
                $table->foreign('conversation_id')->references('id')->on('conversations')->onDelete('cascade');
            } catch (\Exception $e) { /* ignore */ }
            try {
                $table->foreign('channel_id')->references('id')->on('channels')->nullOnDelete();
            } catch (\Exception $e) { /* ignore */ }
            try {
                $table->unique(['conversation_id', 'platform_message_id'], 'product_message_maps_conv_msg_unique');
            } catch (\Exception $e) { /* ignore */ }
        });
    }

    private function ensureIndexExists(): void
    {
        $conn = Schema::getConnection();
        $dbName = $conn->getDatabaseName();
        $driver = $conn->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        $hasIndex = $conn->select(
            "SELECT COUNT(*) as cnt FROM information_schema.STATISTICS " .
            "WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'product_message_maps' AND INDEX_NAME = 'product_message_maps_conv_msg_unique'",
            [$dbName]
        );

        if ($hasIndex[0]->cnt == 0) {
            $this->dropFksAndIndex();
            $this->recreateFksAndIndex();
        }
    }
};
