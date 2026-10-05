<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\SallaService;
use Illuminate\Console\Command;
use Throwable;

class SyncSallaCities extends Command
{
    protected $signature = 'salla:cities:sync {countryCode : ISO country code, for example EG} {channelId? : Connected Salla channel ID; defaults to all connected stores} {--from-page=1 : Resume from this page using the already saved earlier pages}';

    protected $description = 'Sync a Salla country city catalog into the local database';

    public function handle(SallaService $sallaService): int
    {
        $countryCode = strtoupper(trim((string) $this->argument('countryCode')));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $this->error('countryCode must be a two-letter ISO country code, for example EG.');
            return self::FAILURE;
        }

        $channelId = $this->argument('channelId');
        $startPage = (int) $this->option('from-page');
        if ($startPage < 1 || $startPage > 2000) {
            $this->error('--from-page must be between 1 and 2000.');
            return self::FAILURE;
        }

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
                $this->info("Channel {$channel->id}: starting {$countryCode} city sync at page {$startPage} (up to 60 cities per page).");
                $count = $sallaService->syncCityCatalog($channel, $countryCode, function (array $progress) use ($channel): void {
                    if ($progress['stage'] === 'countries_request') {
                        $this->line("Channel {$channel->id}: fetching Salla country catalog...");
                        return;
                    }

                    if ($progress['stage'] === 'page_request') {
                        $this->line("Channel {$channel->id}: requesting {$progress['country_code']} cities page {$progress['page']}...");
                        return;
                    }

                    if ($progress['stage'] === 'page_complete') {
                        $totalPages = max(1, (int) $progress['total_pages']);
                        $this->info(sprintf(
                            'Channel %d: page %d/%d complete; received %d cities (API perPage=%d, %d saved so far).',
                            $channel->id,
                            $progress['page'],
                            $totalPages,
                            $progress['page_count'],
                            $progress['response_per_page'],
                            $progress['total_synced']
                        ));
                    }
                }, $startPage);
                $this->info("Channel {$channel->id}: {$countryCode} catalog now contains {$count} cities.");
            } catch (Throwable $exception) {
                $failed = true;
                $this->error("Channel {$channel->id}: {$exception->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
