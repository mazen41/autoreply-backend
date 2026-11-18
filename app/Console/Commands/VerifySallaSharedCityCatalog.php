<?php

namespace App\Console\Commands;

use App\Services\SharedSallaCityCatalogService;
use Illuminate\Console\Command;
use Throwable;

class VerifySallaSharedCityCatalog extends Command
{
    protected $signature = 'salla:cities:verify {countryCode=SA : ISO country code} {channelId? : Optional second connected store ID}';
    protected $description = 'Compare the complete shared Salla city ID set against another connected store';

    public function handle(SharedSallaCityCatalogService $catalogs): int
    {
        try {
            $result = $catalogs->verifyAgainstSecondStore(
                strtoupper(trim((string) $this->argument('countryCode'))),
                $this->argument('channelId') ? (int) $this->argument('channelId') : null
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $this->table(['Check', 'Result'], [
            ['Second channel ID', $result['channel_id']],
            ['Country', $result['country_code']],
            ['Salla country ID', $result['country_id']],
            ['Pages fetched', $result['total_pages']],
            ['City IDs', $result['total_cities']],
            ['Published catalog SHA-256', $result['catalog_hash']],
            ['Second store SHA-256', $result['second_store_hash']],
            ['ID sets match', $result['matches'] ? 'yes' : 'NO'],
        ]);

        return $result['matches'] ? self::SUCCESS : self::FAILURE;
    }
}
