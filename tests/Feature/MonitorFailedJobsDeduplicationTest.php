<?php

namespace Tests\Feature;

use App\Services\ScheduledFailureNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class MonitorFailedJobsDeduplicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_alert_when_no_failed_jobs(): void
    {
        $mockNotifier = Mockery::mock(ScheduledFailureNotifier::class);
        $mockNotifier->shouldNotReceive('notify');
        $this->app->instance(ScheduledFailureNotifier::class, $mockNotifier);

        $exitCode = Artisan::call('monitor:failed-jobs');
        $this->assertEquals(0, $exitCode);
    }

    public function test_alerts_on_new_failed_jobs_and_deduplicates_on_subsequent_runs(): void
    {
        Cache::flush();

        // Insert a failed job
        DB::table('failed_jobs')->insert([
            'id' => 24,
            'uuid' => 'test-uuid-24',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'TestJob']),
            'exception' => 'Test exception occurred',
            'failed_at' => now(),
        ]);

        $mockNotifier = Mockery::mock(ScheduledFailureNotifier::class);
        // Should only be called ONCE despite running the command twice
        $mockNotifier->shouldReceive('notify')->once();
        $this->app->instance(ScheduledFailureNotifier::class, $mockNotifier);

        // First run: detects job #24 and alerts
        $exitCode1 = Artisan::call('monitor:failed-jobs');
        $this->assertEquals(0, $exitCode1);
        $this->assertEquals(24, (int) Cache::get('ops:monitor:last_alerted_failed_job_id'));

        // Second run: no new jobs since #24, must NOT call notify again
        $exitCode2 = Artisan::call('monitor:failed-jobs');
        $this->assertEquals(0, $exitCode2);
    }

    public function test_alerts_again_when_new_failed_job_arrives(): void
    {
        Cache::flush();

        DB::table('failed_jobs')->insert([
            'id' => 24,
            'uuid' => 'test-uuid-24',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'TestJob']),
            'exception' => 'Test exception occurred',
            'failed_at' => now(),
        ]);

        $mockNotifier = Mockery::mock(ScheduledFailureNotifier::class);
        $mockNotifier->shouldReceive('notify')->twice();
        $this->app->instance(ScheduledFailureNotifier::class, $mockNotifier);

        // Run 1: alerts for #24
        Artisan::call('monitor:failed-jobs');

        // New job #25 arrives
        DB::table('failed_jobs')->insert([
            'id' => 25,
            'uuid' => 'test-uuid-25',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'TestJob2']),
            'exception' => 'Another exception occurred',
            'failed_at' => now(),
        ]);

        // Run 2: alerts for #25
        Artisan::call('monitor:failed-jobs');
        $this->assertEquals(25, (int) Cache::get('ops:monitor:last_alerted_failed_job_id'));
    }
}
