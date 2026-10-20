<?php

namespace Tests\Feature;

use App\Models\SystemHealthLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminSystemHealthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_is_admin_only_and_redacts_sensitive_event_details(): void
    {
        $this->getJson('/api/admin/system-health')->assertUnauthorized();
        $admin = User::factory()->create(['is_admin' => true]);
        SystemHealthLog::create([
            'component' => 'scheduler',
            'status' => 'critical',
            'message' => 'Do not expose this raw message containing a token.',
            'details' => ['task' => 'poll-gmail', 'token' => 'sensitive-token-marker'],
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/system-health')->assertOk();
        $response->assertJsonPath('events.0.message', 'A scheduled task reported a failure.');
        $this->assertStringNotContainsString('sensitive-token-marker', $response->getContent());
        $this->assertStringNotContainsString('Do not expose this raw message', $response->getContent());
    }

    public function test_queue_endpoint_does_not_return_failed_payload_or_stack_trace(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $secret = 'failed-job-secret-marker';
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => \App\Jobs\ProcessAutoReply::class, 'secret' => $secret]),
            'exception' => "RuntimeException: {$secret}\n#0 /srv/app/vendor/file.php(1)",
            'failed_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')->getJson('/api/admin/queue/stats')->assertOk();
        $response->assertJsonPath('failed_jobs_list.data.0.retry_safe', false);
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString('payload', $response->getContent());
        $this->assertStringNotContainsString('/srv/app/', $response->getContent());
    }
}
