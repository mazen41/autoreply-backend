<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStep;
use App\Models\SequenceStepExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SequenceQueueHealthScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_queue_health_score_reflects_failed_executions(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Health Test Biz',
            'business_type' => 'retail',
        ]);

        $sequence = Sequence::create([
            'business_id' => $business->id,
            'name' => 'Test Sequence',
            'status' => 'active',
        ]);

        $step = SequenceStep::create([
            'sequence_id' => $sequence->id,
            'step_order' => 1,
            'action_type' => 'send_message',
            'delay_minutes' => 0,
            'action_payload' => ['message' => 'Hello'],
        ]);

        $channel = \App\Models\Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        $conversation = \App\Models\Conversation::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => 'test-sender-1',
            'status' => 'open',
        ]);

        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
            'started_at' => now(),
        ]);

        // 1 failed execution
        SequenceStepExecution::create([
            'sequence_id' => $sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'execution_key' => 'step-1-' . $enrollment->id,
            'status' => 'failed',
            'scheduled_at' => now()->subMinutes(10),
            'executed_at' => now()->subMinutes(5),
            'error' => 'Channel error',
        ]);

        // Call sequence:monitor-queue
        Artisan::call('sequence:monitor-queue');
        $output = Artisan::output();

        // Must NOT report 100% health
        $this->assertStringNotContainsString('(100%)', $output);
        $this->assertStringContainsString('failed execution(s) detected', $output);

        // Also test API endpoint
        $response = $this->actingAs($user)->getJson('/api/sequences/queue/metrics');
        $response->assertStatus(200);
        $score = $response->json('health.score');
        $this->assertLessThan(100, $score);
        $this->assertGreaterThan(0, $score);
    }
}
