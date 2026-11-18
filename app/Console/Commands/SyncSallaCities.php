<?php

namespace App\Console\Commands;

use App\Jobs\SyncSallaSharedCityCatalog;
use Illuminate\Console\Command;

class SyncSallaCities extends Command
{
    protected $signature = 'salla:cities:sync {countryCode : ISO country code, for example SA}';

    protected $description = 'Queue a shared Salla country city catalog sync';

    public function handle(): int
    {
        $countryCode = strtoupper(trim((string) $this->argument('countryCode')));
        if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
            $this->error('countryCode must be a two-letter ISO country code, for example EG.');
            return self::FAILURE;
        }

        SyncSallaSharedCityCatalog::dispatch($countryCode);
        $this->info("Queued one shared {$countryCode} Salla city catalog sync; the sync selects a healthy connected store as its metadata source.");
        return self::SUCCESS;
    }
}
