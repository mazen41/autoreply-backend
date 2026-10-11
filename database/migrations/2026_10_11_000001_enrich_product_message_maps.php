<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_message_maps', function (Blueprint $table) {
            $table->string('provider', 32)->nullable()->after('channel_id');
            $table->string('commerce_provider', 32)->nullable()->after('provider');
            $table->string('recipient_id')->nullable()->after('platform_message_id');
            $table->timestamp('sent_at')->nullable()->after('recipient_id');
            $table->unsignedBigInteger('internal_product_id')->nullable()->after('salla_product_id');
            $table->unsignedBigInteger('internal_message_id')->nullable()->after('platform_message_id');
            $table->string('platform_message_id')->nullable()->change();
            $table->index(['conversation_id', 'commerce_channel_id', 'created_at'], 'product_maps_recent_store_idx');
            $table->index('internal_message_id', 'product_maps_internal_message_idx');
        });

        DB::statement(<<<'SQL'
            UPDATE product_message_maps
            SET provider = (
                SELECT channels.type FROM channels
                WHERE channels.id = product_message_maps.channel_id
            )
            WHERE provider IS NULL
        SQL);

        DB::statement(<<<'SQL'
            UPDATE product_message_maps
            SET commerce_provider = (
                SELECT channels.type FROM channels
                WHERE channels.id = product_message_maps.commerce_channel_id
            )
            WHERE commerce_provider IS NULL
        SQL);

        // Historical Salla maps used salla_product_id as their provider ID.
        DB::statement(<<<'SQL'
            UPDATE product_message_maps
            SET commerce_external_id = salla_product_id,
                commerce_provider = 'salla'
            WHERE commerce_external_id IS NULL
              AND salla_product_id IS NOT NULL
              AND commerce_channel_id IN (SELECT channels.id FROM channels WHERE channels.type = 'salla')
        SQL);

        DB::statement('UPDATE product_message_maps SET sent_at = created_at WHERE sent_at IS NULL');

        DB::statement(<<<'SQL'
            UPDATE product_message_maps
            SET internal_product_id = (
                SELECT products.id FROM products
                WHERE products.commerce_channel_id = product_message_maps.commerce_channel_id
                  AND products.commerce_external_id = product_message_maps.commerce_external_id
                LIMIT 1
            )
            WHERE internal_product_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('product_message_maps', function (Blueprint $table) {
            $table->dropIndex('product_maps_recent_store_idx');
            $table->dropIndex('product_maps_internal_message_idx');
            $table->dropColumn(['provider', 'commerce_provider', 'recipient_id', 'sent_at', 'internal_product_id', 'internal_message_id']);
        });
    }
};
