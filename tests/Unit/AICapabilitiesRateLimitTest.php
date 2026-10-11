<?php

namespace Tests\Unit;

use App\Services\AICapabilitiesService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AICapabilitiesRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_rate_limit_counts_requests_in_cache_and_is_tenant_scoped(): void
    {
        for ($request = 1; $request <= 10; $request++) {
            $result = AICapabilitiesService::checkRateLimit('user-1', 'tenant-1');
            $this->assertFalse($result['rate_limited']);
        }

        $this->assertTrue(AICapabilitiesService::checkRateLimit('user-1', 'tenant-1')['rate_limited']);
        $this->assertFalse(AICapabilitiesService::checkRateLimit('user-1', 'tenant-2')['rate_limited']);
    }
}
