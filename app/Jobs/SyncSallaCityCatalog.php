<?php

namespace App\Jobs;

use App\Models\Channel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncSallaCityCatalog implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [30, 120, 300, 900];
    public int $uniqueFor = 3600;

    public function __construct(public int $channelId, public string $countryCode = 'SA')
    {
    }

    public function uniqueId(): string
    {
        return 'salla-city-catalog:' . $this->channelId . ':' . strtoupper($this->countryCode);
    }

    /**
     * Backward-compatible adapter for queue payloads created before the shared
     * catalog cutover. It never performs a per-channel fetch or write.
     */
    public function handle(): void
    {
        $channel = Channel::query()
            ->whereKey($this->channelId)
            ->where('type', 'salla')
            ->where('status', 'connected')
            ->whereNotNull('user_id')
            ->whereNotNull('business_id')
            ->first();

        if (!$channel) {
            return;
        }

        SyncSallaSharedCityCatalog::dispatch(strtoupper($this->countryCode));
    }
}
