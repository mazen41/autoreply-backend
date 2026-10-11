<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /** Remove legacy commerce-store rows from the communication-channel pivot. */
    public function up(): void
    {
        DB::table('bot_channels as bot_channel')
            ->join('channels', 'channels.id', '=', 'bot_channel.channel_id')
            ->whereIn('channels.type', ['salla', 'shopify', 'woocommerce'])
            ->select(['bot_channel.id as pivot_id', 'bot_channel.bot_id'])
            ->orderBy('bot_channel.id')
            ->chunkById(500, function ($rows): void {
                $pivotIds = $rows->pluck('pivot_id')->all();
                $botIds = $rows->pluck('bot_id')->unique()->values()->all();

                Log::warning('Removing legacy commerce-store rows from bot channel assignments', [
                    'bot_ids' => $botIds,
                    'assignment_count' => count($pivotIds),
                ]);

                DB::table('bot_channels')->whereIn('id', $pivotIds)->delete();
            }, 'bot_channel.id', 'pivot_id');
    }

    public function down(): void
    {
        // Intentionally a no-op: deleted legacy assignments cannot be restored safely.
    }
};
