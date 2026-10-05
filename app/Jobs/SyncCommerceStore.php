<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\CommerceStoreSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncCommerceStore implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 3600;
    public array $backoff = [60, 300];

    public function __construct(public int $channelId)
    {
    }

    public function handle(CommerceStoreSyncService $syncService): void
    {
        $lock = Cache::lock("commerce_store_sync_{$this->channelId}", 3700);
        if (!$lock->get()) {
            return;
        }

        try {
            $channel = Channel::whereIn('type', ['shopify', 'woocommerce'])
                ->where('status', 'connected')
                ->find($this->channelId);
            if (!$channel) {
                return;
            }

            $counts = $syncService->sync($channel);
            Log::info('Commerce store sync completed', [
                'channel_id' => $channel->id,
                'provider' => $channel->type,
                'counts' => $counts,
            ]);
        } catch (\Throwable $e) {
            Log::error('Commerce store sync failed', [
                'channel_id' => $this->channelId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
