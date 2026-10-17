<?php

namespace App\Console\Commands;

use App\Jobs\SyncSallaOrders as SyncSallaOrdersJob;
use App\Models\Channel;
use Illuminate\Console\Command;

class SyncSallaOrders extends Command
{
    protected $signature = 'salla:sync-orders {channelId? : Specific connected Salla channel ID} {--sync : Run synchronously instead of queueing}';

    protected $description = 'Sync orders from connected Salla stores';

    public function handle(): int
    {
        $channelId = $this->argument('channelId');

        $channels = Channel::query()
            ->where('type', 'salla')
            ->where('status', 'connected')
            ->when($channelId, fn ($query) => $query->whereKey((int) $channelId))
            ->get();

        if ($channels->isEmpty()) {
            $this->info($channelId
                ? "No connected Salla channel found with ID {$channelId}."
                : 'No connected Salla channels found for order sync.');
            return self::SUCCESS;
        }

        $runSync = (bool) $this->option('sync');

        foreach ($channels as $channel) {
            $this->info("Dispatching order sync for Salla channel #{$channel->id} ({$channel->name})...");

            if ($runSync) {
                SyncSallaOrdersJob::dispatchSync($channel->id);
            } else {
                SyncSallaOrdersJob::dispatch($channel->id);
            }
        }

        $this->info("Dispatched order sync for {$channels->count()} Salla channel(s).");
        return self::SUCCESS;
    }
}
