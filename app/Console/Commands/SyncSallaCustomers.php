<?php

namespace App\Console\Commands;

use App\Jobs\SyncSallaCustomers as SyncSallaCustomersJob;
use App\Models\Channel;
use Illuminate\Console\Command;

class SyncSallaCustomers extends Command
{
    protected $signature = 'salla:sync-customers {channelId? : Specific connected Salla channel ID} {--sync : Run synchronously instead of queueing}';

    protected $description = 'Sync customers from connected Salla stores';

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
                : 'No connected Salla channels found for customer sync.');
            return self::SUCCESS;
        }

        $runSync = (bool) $this->option('sync');

        foreach ($channels as $channel) {
            $this->info("Dispatching customer sync for Salla channel #{$channel->id} ({$channel->name})...");

            if ($runSync) {
                SyncSallaCustomersJob::dispatchSync($channel->id);
            } else {
                SyncSallaCustomersJob::dispatch($channel->id);
            }
        }

        $this->info("Dispatched customer sync for {$channels->count()} Salla channel(s).");
        return self::SUCCESS;
    }
}
