<?php

namespace App\Console\Commands;

use App\Jobs\SyncSallaCityCatalog;
use App\Models\Channel;
use Illuminate\Console\Command;

class QueueSallaCityCatalogRefresh extends Command
{
    protected $signature = 'salla:cities:queue-refresh';
    protected $description = 'Queue a refresh of configured Salla delivery city catalogs';

    public function handle(): int
    {
        $countries = (array) config('services.salla.city_catalog_countries', ['SA']);
        $countries = array_values(array_unique(array_filter(array_map(
            static fn ($code) => strtoupper(trim((string) $code)),
            $countries
        ), static fn ($code) => preg_match('/^[A-Z]{2}$/', $code) === 1)));

        if ($countries === []) {
            $this->error('No valid Salla city catalog country codes are configured.');
            return self::FAILURE;
        }

        $queued = 0;
        Channel::query()->where('type', 'salla')->where('status', 'connected')->each(function (Channel $channel) use ($countries, &$queued): void {
            foreach ($countries as $countryCode) {
                SyncSallaCityCatalog::dispatch((int) $channel->id, $countryCode);
                $queued++;
            }
        });

        $this->info("Queued {$queued} Salla city catalog refresh job(s).");
        return self::SUCCESS;
    }
}
