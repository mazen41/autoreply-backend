<?php

namespace App\Console\Commands;

use App\Services\SharedSallaCityCatalogService;
use Illuminate\Console\Command;

class QueueSallaCityCatalogRefresh extends Command
{
    protected $signature = 'salla:cities:queue-refresh';
    protected $description = 'Queue a refresh of configured Salla delivery city catalogs';

    public function handle(SharedSallaCityCatalogService $catalogs): int
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
        foreach ($countries as $countryCode) {
            if ($catalogs->queueIfStale($countryCode)) {
                $queued++;
            }
        }

        $this->info("Queued {$queued} stale shared Salla city catalog refresh job(s).");
        return self::SUCCESS;
    }
}
