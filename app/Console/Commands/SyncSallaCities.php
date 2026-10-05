<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\SallaService;
use Illuminate\Console\Command;
use Throwable;

class SyncSallaCities extends Command
{
    protected $signature = 'salla:cities:sync {countryCode : ISO country code, for example EG} {channelId? : Connected Salla channel ID; defaults to all connected stores}';

    protected $description = 'Sync a Salla country city catalog into the local database';

    public function handle(SallaService $sallaService): int
    {
        $countryCode = strtoupper(trim((string) $this->argument('countryCode')));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $this->error('countryCode must be a two-letter ISO country code, for example EG.');
            return self::FAILURE;
        }

        $channelId = $this->argument('channelId');
        $channels = Channel::query()
            ->where('type', 'salla')
            ->where('status', 'connected')
            ->when($channelId, fn ($query) => $query->whereKey((int) $channelId))
            ->get();

        if ($channels->isEmpty()) {
            $this->error($channelId
                ? "No connected Salla channel found with ID {$channelId}."
                : 'No connected Salla channels found.');
            return self::FAILURE;
        }

        $failed = false;
        foreach ($channels as $channel) {
            try {
                $count = $sallaService->syncCityCatalog($channel, $countryCode);
                $this->info("Channel {$channel->id}: synced {$count} {$countryCode} cities.");
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("Channel {$channel->id}: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
