<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename whatsapp_message_id → platform_message_id on product_message_maps
 * for channel neutrality (already applied to the create migration for fresh
 * installs). This migration handles existing production tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_message_maps', function (Blueprint $table) {
            if (Schema::hasColumn('product_message_maps', 'whatsapp_message_id')
                && !Schema::hasColumn('product_message_maps', 'platform_message_id')) {
                $table->renameColumn('whatsapp_message_id', 'platform_message_id');
            }
        });

        // Recreate unique index with new column name
        Schema::table('product_message_maps', function (Blueprint $table) {
            try {
                $table->dropUnique('product_message_maps_conv_msg_unique');
            } catch (\Exception $e) {
                // Index may not exist
            }
            $table->unique(['conversation_id', 'platform_message_id'], 'product_message_maps_conv_msg_unique');
        });
    }

    public function down(): void
    {
        Schema::table('product_message_maps', function (Blueprint $table) {
            if (Schema::hasColumn('product_message_maps', 'platform_message_id')) {
                $table->renameColumn('platform_message_id', 'whatsapp_message_id');
            }
        });

        Schema::table('product_message_maps', function (Blueprint $table) {
            try {
                $table->dropUnique('product_message_maps_conv_msg_unique');
            } catch (\Exception $e) {
                // Index may not exist
            }
            $table->unique(['conversation_id', 'whatsapp_message_id'], 'product_message_maps_conv_msg_unique');
        });
    }
};
