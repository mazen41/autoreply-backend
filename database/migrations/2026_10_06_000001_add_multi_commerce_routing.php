<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('default_ecommerce_connection_id')
                ->nullable()
                ->after('business_id')
                ->constrained('channels')
                ->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            // Web Chat conversations have a business/widget identity without a
            // provider account row. Keep the FK for all other channels.
            $table->dropForeign(['channel_id']);
            $table->unsignedBigInteger('channel_id')->nullable()->change();
            $table->foreign('channel_id')->references('id')->on('channels')->cascadeOnDelete();

            $table->foreignId('ecommerce_connection_id')
                ->nullable()
                ->after('bot_id')
                ->constrained('channels')
                ->nullOnDelete();
            $table->string('commerce_context_status', 24)->default('unresolved')->after('ecommerce_connection_id');
            $table->string('commerce_context_source', 32)->nullable()->after('commerce_context_status');
            $table->timestamp('commerce_context_resolved_at')->nullable()->after('commerce_context_source');
            $table->index(['business_id', 'commerce_context_status'], 'conversations_commerce_context_index');
        });

        Schema::create('bot_ecommerce_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('bots')->cascadeOnDelete();
            $table->foreignId('ecommerce_connection_id')->constrained('channels')->cascadeOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['bot_id', 'ecommerce_connection_id'], 'bot_ecommerce_connections_unique');
            $table->index(['bot_id', 'is_enabled', 'is_default'], 'bot_ecommerce_default_lookup_index');
        });

        // Preserve every legacy Bot store assignment as an enabled default.
        // Only links within the Bot's business and to a supported commerce
        // account are imported; invalid cross-business references are ignored
        // and left intact in the legacy column for diagnosis/repair.
        DB::table('bots as b')
            ->join('channels as c', 'c.id', '=', 'b.ecommerce_channel_id')
            ->whereColumn('c.business_id', 'b.business_profile_id')
            ->whereIn('c.type', ['salla', 'shopify', 'woocommerce'])
            ->select(['b.id as bot_id', 'c.id as connection_id'])
            ->orderBy('b.id')
            ->chunk(500, function ($links): void {
                foreach ($links as $link) {
                    DB::table('bot_ecommerce_connections')->insertOrIgnore([
                        'bot_id' => $link->bot_id,
                        'ecommerce_connection_id' => $link->connection_id,
                        'is_enabled' => true,
                        'is_default' => true,
                        'settings' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

        // Existing conversations retain their Bot's previously selected store
        // so the first post-deploy message cannot switch them to another store.
        DB::table('conversations as cv')
            ->join('bots as b', 'b.id', '=', 'cv.bot_id')
            ->join('channels as c', 'c.id', '=', 'b.ecommerce_channel_id')
            ->whereNull('cv.ecommerce_connection_id')
            ->whereColumn('cv.business_id', 'b.business_profile_id')
            ->whereColumn('c.business_id', 'b.business_profile_id')
            ->whereIn('c.type', ['salla', 'shopify', 'woocommerce'])
            ->where('c.status', 'connected')
            ->select(['cv.id as id', 'cv.id as conversation_id', 'c.id as connection_id'])
            ->orderBy('cv.id')
            ->chunkById(500, function ($conversations): void {
                foreach ($conversations as $row) {
                    DB::table('conversations')
                        ->where('id', $row->conversation_id)
                        ->update([
                            'ecommerce_connection_id' => $row->connection_id,
                            'commerce_context_status' => 'resolved',
                            'commerce_context_source' => 'legacy_bot_default',
                            'commerce_context_resolved_at' => now(),
                        ]);
                }
            }, 'cv.id', 'id');
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_ecommerce_connections');

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_commerce_context_index');
            $table->dropConstrainedForeignId('ecommerce_connection_id');
            $table->dropColumn([
                'commerce_context_status',
                'commerce_context_source',
                'commerce_context_resolved_at',
            ]);
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_ecommerce_connection_id');
        });

        // channel_id intentionally remains nullable: migrated Web Chat
        // conversations may now depend on that state, and rollback must not
        // make those conversations invalid or delete their data.
    }
};
