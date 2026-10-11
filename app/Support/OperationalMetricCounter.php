<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class OperationalMetricCounter
{
    private const WINDOW_SECONDS = 300;

    public function increment(string $metric): void
    {
        $bucket = $this->currentBucket();
        $key = $this->key($metric, $bucket);
        Cache::add($key, 0, now()->addMinutes(15));
        Cache::increment($key);
    }

    public function currentWindowCount(string $metric): int
    {
        return (int) Cache::get($this->key($metric, $this->currentBucket()), 0);
    }

    private function currentBucket(): int
    {
        return intdiv(now()->timestamp, self::WINDOW_SECONDS) * self::WINDOW_SECONDS;
    }

    private function key(string $metric, int $bucket): string
    {
        return 'ops:metric:' . preg_replace('/[^a-z0-9_\-]/i', '_', $metric) . ':' . $bucket;
    }
}
