<?php

namespace Tests\Feature;

use App\Models\SystemHealthLog;
use App\Services\SystemHealthMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SystemHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_health_log_model_creation_and_scopes(): void
    {
        $log = SystemHealthLog::create([
            'component' => 'webhook',
            'status' => 'critical',
            'message' => 'High webhook failure rate',
            'details' => ['failures' => 15],
            'is_resolved' => false,
        ]);

        $this->assertDatabaseHas('system_health_logs', [
            'id' => $log->id,
            'component' => 'webhook',
            'status' => 'critical',
            'is_resolved' => false,
        ]);

        $this->assertEquals(1, SystemHealthLog::unresolved()->count());
        $this->assertEquals(1, SystemHealthLog::critical()->count());
        $this->assertEquals(1, SystemHealthLog::component('webhook')->count());

        $log->markResolved();
        $this->assertTrue($log->fresh()->is_resolved);
        $this->assertNotNull($log->fresh()->resolved_at);
        $this->assertEquals(0, SystemHealthLog::unresolved()->count());
    }

    public function test_health_monitoring_service_returns_status_and_issues(): void
    {
        $service = new SystemHealthMonitoringService();
        $result = $service->runHealthCheck();

        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('overall_status', $result);
        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('issues', $result);
        $this->assertIsArray($result['issues']);
        $this->assertEquals($result['status'], $result['overall_status']);
    }

    public function test_system_health_check_artisan_command_succeeds(): void
    {
        $exitCode = Artisan::call('system:health-check');
        $this->assertEquals(0, $exitCode);
    }
}
