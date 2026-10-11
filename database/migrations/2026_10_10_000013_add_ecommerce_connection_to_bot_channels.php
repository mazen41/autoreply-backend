<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_channels', function (Blueprint $table): void {
            $table->foreignId('ecommerce_connection_id')
                ->nullable()
                ->after('channel_id')
                ->constrained('channels')
                ->nullOnDelete();
        });

        // Migrate legacy global channel routing only when that store is
        // explicitly enabled for the Bot and belongs to the same business.
        DB::table('bot_channels as bc')
            ->join('bots as b', 'b.id', '=', 'bc.bot_id')
            ->join('channels as social', 'social.id', '=', 'bc.channel_id')
            ->whereNull('bc.ecommerce_connection_id')
            ->whereNotNull('social.default_ecommerce_connection_id')
            ->whereColumn('social.business_id', 'b.business_profile_id')
            ->select(['bc.id as pivot_id', 'bc.bot_id', 'social.default_ecommerce_connection_id as connection_id'])
            ->orderBy('bc.id')
            ->chunkById(500, function ($routes): void {
                foreach ($routes as $route) {
                    $eligible = DB::table('bot_ecommerce_connections as bec')
                        ->join('channels as commerce', 'commerce.id', '=', 'bec.ecommerce_connection_id')
                        ->where('bec.bot_id', $route->bot_id)
                        ->where('bec.ecommerce_connection_id', $route->connection_id)
                        ->where('bec.is_enabled', true)
                        ->where('commerce.status', 'connected')
                        ->whereIn('commerce.type', ['salla', 'shopify', 'woocommerce'])
                        ->exists();

                    if ($eligible) {
                        DB::table('bot_channels')
                            ->where('id', $route->pivot_id)
                            ->whereNull('ecommerce_connection_id')
                            ->update(['ecommerce_connection_id' => $route->connection_id]);
                    }
                }
            }, 'bc.id', 'pivot_id');
    }

    public function down(): void
    {
        Schema::table('bot_channels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ecommerce_connection_id');
        });
    }
};
