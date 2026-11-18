<?php

namespace App\Jobs;

use App\Services\SharedSallaCityCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncSallaSharedCityCatalog implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 8;
    public int $timeout = 1200;
    public int $uniqueFor = 3600;
    public array $backoff = [30, 120, 300, 900, 1800];

    public function __construct(public string $countryCode)
    {
        $this->countryCode = strtoupper(trim($countryCode));
    }

    public function uniqueId(): string
    {
        return 'salla-shared-city-catalog:' . strtoupper($this->countryCode);
    }

    public function handle(SharedSallaCityCatalogService $catalogs): void
    {
        $result = $catalogs->syncChunk($this->countryCode);
        if (!($result['done'] ?? false)) {
            // Another worker may own the country lock. Requeue so a transient
            // lock does not silently strand a catalog until the next daily run.
            self::dispatch($this->countryCode)->delay(now()->addSeconds(($result['locked'] ?? false) ? 60 : 2));
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
