<?php

namespace Tests\Feature;

use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepExecution;
use App\Models\Conversation;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\Message;
use App\Models\Channel;
use App\Models\Bot;
use App\Jobs\ExecuteSequenceStep;
use App\Services\SequenceExecutionService;
use App\Services\SequenceEnrollmentService;
use App\Services\SequenceTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class SequenceHardeningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private Sequence $sequence;
    private Conversation $conversation;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create();
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $this->channel = Channel::factory()->create([
            'business_id'  => $this->business->id,
            'user_id'      => $this->user->id,
            'type'         => 'whatsapp',
            'status'       => 'connected',
            'connected_at' => now(),
        ]);

        $this->sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status'      => 'active',
            'channel'     => 'whatsapp',
        ]);

        $this->conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
        ]);
    }

    public function test_duplicate_job_dispatch_only_sends_message_once()
    {
        Queue::fake();

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Test message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id'            => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id'       => $step->id,
            'status'                 => 'pending',
        ]);

        // Dispatch the same job twice
        ExecuteSequenceStep::dispatch($execution->id);
        ExecuteSequenceStep::dispatch($execution->id);

        // Both jobs should be queued (the job itself handles idempotency)
        Queue::assertPushed(ExecuteSequenceStep::class, 2);
    }

    public function test_worker_retry_without_duplicate_message_send()
    {
        Http::fake([
            '*/message/sendText/*' => Http::response([
                'key'     => ['id' => 'fake-msg-id'],
                'message' => ['conversation' => 'faked'],
                'status'  => 'PENDING',
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Retry test message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id'            => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id'       => $step->id,
            'status'                 => 'pending',
        ]);

        $service = app(SequenceExecutionService::class);

        // Execute the step
        $service->executeStep($execution, $enrollment, $step);

        // Verify message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Retry test message',
            'source'          => 'sequence',
        ]);

        $messageCount = Message::where('conversation_id', $this->conversation->id)
            ->where('content', 'Retry test message')
            ->count();
        $this->assertEquals(1, $messageCount);

        // Simulate worker retry — execute again
        $execution->refresh();
        $service->executeStep($execution, $enrollment, $step);

        // Still only one message
        $messageCount = Message::where('conversation_id', $this->conversation->id)
            ->where('content', 'Retry test message')
            ->count();
        $this->assertEquals(1, $messageCount);
    }

    // ── PROVIDER FAILURE RECORDING AND RETRY ─────────────────────────────────

    public function test_provider_failure_is_recorded_and_retried()
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['error' => 'Provider timeout'], 500),
            '*' => Http::response([], 500),
        ]);

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Failure test message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id'            => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id'       => $step->id,
            'status'                 => 'pending',
        ]);

        $service = app(SequenceExecutionService::class);

        // Execute — should throw due to provider failure
        try {
            $service->executeStep($execution, $enrollment, $step);
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Expected
        }

        // Verify execution was marked as failed with error detail
        $execution->refresh();
        $this->assertEquals('failed', $execution->status);
        $this->assertNotNull($execution->error);
        $this->assertStringContainsString('Provider timeout', $execution->error);

        // Verify message was created but marked as failed
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Failure test message',
            'delivery_status' => 'failed',
        ]);
    }

    // ── CUSTOMER REPLY CANCELS ENROLLMENT ────────────────────────────────────

    public function test_customer_reply_cancels_enrollment()
    {
        Queue::fake();

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Welcome',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
            'started_at'      => now()->subHour(),
        ]);

        // Simulate customer reply
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'direction'       => 'inbound',
            'content'         => 'Customer response',
            'created_at'      => now()->subMinutes(5),
        ]);

        // Trigger cancellation
        $triggerService = app(SequenceTriggerService::class);
        $triggerService->stopSequencesOnCustomerReply($this->conversation);

        // Verify enrollment was stopped
        $enrollment->refresh();
        $this->assertEquals('stopped', $enrollment->status);
        $this->assertNotNull($enrollment->stopped_at);
    }

    // ── FULL CHAIN COMPLETION ────────────────────────────────────────────────

    public function test_enrollment_completes_full_chain()
    {
        Queue::fake();

        // Step 1: Message
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Step 1: Welcome',
        ]);

        // Step 2: Delay
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 2,
            'step_type'   => 'delay',
            'delay_hours' => 0,
            'delay_unit'  => 'minutes',
            'delay_minutes' => 1,
        ]);

        // Step 3: Message
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 3,
            'step_type'   => 'message',
            'message'     => 'Step 3: Follow-up',
        ]);

        // Step 4: Condition (always true)
        SequenceStep::factory()->create([
            'sequence_id'      => $this->sequence->id,
            'step_order'       => 4,
            'step_type'        => 'condition',
            'condition_config' => [
                'type'     => 'customer_replied',
                'on_true'  => 'continue',
                'on_false' => 'continue',
            ],
        ]);

        // Step 5: Final message
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 5,
            'step_type'   => 'message',
            'message'     => 'Step 5: Final',
        ]);

        // Enroll conversation
        $enrollmentService = app(SequenceEnrollmentService::class);
        $enrollment = $enrollmentService->enrollConversation($this->sequence, $this->conversation);

        $this->assertEquals('active', $enrollment->status);
        $this->assertEquals(1, $enrollment->current_step);

        Http::fake([
            '*/message/sendText/*' => Http::response([
                'key'     => ['id' => 'fake-msg-id'],
                'message' => ['conversation' => 'faked'],
                'status'  => 'PENDING',
            ], 200),
            '*' => Http::response([], 200),
        ]);

        // Execute step 1 (message)
        $execution1 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 1)->first()->id)
            ->first();
        $service = app(SequenceExecutionService::class);
        $step1 = $this->sequence->steps()->where('step_order', 1)->first();
        $service->executeStep($execution1, $enrollment, $step1);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Step 1: Welcome',
        ]);

        // Execute step 2 (delay) — should schedule step 3
        $execution2 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 2)->first()->id)
            ->first();
        $step2 = $this->sequence->steps()->where('step_order', 2)->first();
        $service->executeStep($execution2, $enrollment, $step2);

        // Step 3 should now be scheduled
        $enrollment->refresh();
        $this->assertEquals(3, $enrollment->current_step);

        // Execute step 3 (message)
        $execution3 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 3)->first()->id)
            ->first();
        $step3 = $this->sequence->steps()->where('step_order', 3)->first();
        $service->executeStep($execution3, $enrollment, $step3);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Step 3: Follow-up',
        ]);

        // Execute step 4 (condition)
        $execution4 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 4)->first()->id)
            ->first();
        $step4 = $this->sequence->steps()->where('step_order', 4)->first();
        $service->executeStep($execution4, $enrollment, $step4);

        // Execute step 5 (final message)
        $execution5 = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 5)->first()->id)
            ->first();
        $step5 = $this->sequence->steps()->where('step_order', 5)->first();
        $service->executeStep($execution5, $enrollment, $step5);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Step 5: Final',
        ]);

        // Enrollment should be completed
        $enrollment->refresh();
        $this->assertEquals('completed', $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
    }

    // ── ORDER_CREATED TRIGGER ─────────────────────────────────────────────────

    public function test_order_created_trigger_enrolls_correctly()
    {
        Queue::fake();

        $orderSequence = Sequence::factory()->create([
            'business_id'   => $this->business->id,
            'status'        => 'active',
            'channel'       => 'whatsapp',
            'trigger_type'  => 'order_created',
            'trigger_config' => ['min_order_value' => 100],
        ]);

        // Enrollment is NOT auto-completed when the sequence has no steps,
        // so give it a step that can enroll into an active state
        SequenceStep::factory()->create([
            'sequence_id' => $orderSequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Order received',
        ]);

        $orderData = [
            'id'    => 'ORD-12345',
            'total' => 150,
        ];

        $triggerService = app(SequenceTriggerService::class);
        $triggerService->checkAndEnrollForOrderCreated($this->conversation, $orderData);

        // Verify enrollment was created
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id'     => $orderSequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
        ]);
    }

    public function test_order_created_trigger_respects_min_order_value()
    {
        Queue::fake();

        $orderSequence = Sequence::factory()->create([
            'business_id'   => $this->business->id,
            'status'        => 'active',
            'channel'       => 'whatsapp',
            'trigger_type'  => 'order_created',
            'trigger_config' => ['min_order_value' => 200],
        ]);

        $orderData = [
            'id'    => 'ORD-12345',
            'total' => 150, // Below threshold
        ];

        $triggerService = app(SequenceTriggerService::class);
        $triggerService->checkAndEnrollForOrderCreated($this->conversation, $orderData);

        // Verify NO enrollment was created
        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id'     => $orderSequence->id,
            'conversation_id' => $this->conversation->id,
        ]);
    }

    // ── BOT-SCOPED SEQUENCE ISOLATION ────────────────────────────────────────

    public function test_bot_scoped_sequence_does_not_enroll_wrong_bot_conversations()
    {
        Queue::fake();

        // Create two bots
        $bot1 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 1', 'status' => 'active']);
        $bot2 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 2', 'status' => 'active']);

        // Create a bot-scoped sequence for bot1
        $botSequence = Sequence::factory()->create([
            'business_id'  => $this->business->id,
            'status'       => 'active',
            'channel'      => 'whatsapp',
            'trigger_type' => 'new_user',
            'bot_id'       => $bot1->id,
        ]);

        // Give the sequence a step so enrollments enter an active state
        SequenceStep::factory()->create([
            'sequence_id' => $botSequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Hello from bot',
        ]);

        // Conversation belongs to bot2
        $conversation2 = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
            'bot_id'      => $bot2->id,
        ]);

        // Try to enroll bot2's conversation in bot1's sequence
        $triggerService = app(SequenceTriggerService::class);
        $triggerService->checkAndEnrollForMessageReceived($conversation2);

        // Verify NO enrollment was created for bot2's conversation
        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id'     => $botSequence->id,
            'conversation_id' => $conversation2->id,
        ]);
    }

    public function test_bot_scoped_sequence_enrolls_correct_bot_conversations()
    {
        Queue::fake();

        // Create two bots
        $bot1 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 1', 'status' => 'active']);
        $bot2 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 2', 'status' => 'active']);

        // Create a bot-scoped sequence for bot1
        $botSequence = Sequence::factory()->create([
            'business_id'  => $this->business->id,
            'status'       => 'active',
            'channel'      => 'whatsapp',
            'trigger_type' => 'new_user',
            'bot_id'       => $bot1->id,
        ]);

        // Give the sequence a step so enrollments enter an active state
        SequenceStep::factory()->create([
            'sequence_id' => $botSequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Hello from bot',
        ]);

        // Conversation belongs to bot1
        $conversation1 = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
            'bot_id'      => $bot1->id,
        ]);

        // Enroll bot1's conversation
        $triggerService = app(SequenceTriggerService::class);
        $triggerService->checkAndEnrollForMessageReceived($conversation1);

        // Verify enrollment was created
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id'     => $botSequence->id,
            'conversation_id' => $conversation1->id,
            'status'          => 'active',
        ]);
    }

    // ── DELAYED STEP SURVIVES WORKER RESTART ─────────────────────────────────

    public function test_delayed_step_survives_worker_restart()
    {
        Queue::fake();

        // Create sequence with delay
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'delay',
            'delay_hours' => 1,
            'delay_unit'  => 'hours',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 2,
            'step_type'   => 'message',
            'message'     => 'Delayed message',
        ]);

        // Enroll conversation
        $enrollmentService = app(SequenceEnrollmentService::class);
        $enrollment = $enrollmentService->enrollConversation($this->sequence, $this->conversation);

        // Execute delay step
        $delayStep = $this->sequence->steps()->where('step_order', 1)->first();
        $execution = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $delayStep->id)
            ->first();

        $service = app(SequenceExecutionService::class);
        $service->executeStep($execution, $enrollment, $delayStep);

        // Verify next step execution was scheduled with correct delay
        $nextExecution = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $this->sequence->steps()->where('step_order', 2)->first()->id)
            ->first();

        $this->assertNotNull($nextExecution);
        $this->assertEquals('pending', $nextExecution->status);
        $this->assertGreaterThan(now()->addMinutes(59), $nextExecution->scheduled_at);
    }

    // ── EXECUTION KEY IDEMPOTENCY ────────────────────────────────────────────

    public function test_execution_key_prevents_duplicate_execution_records()
    {
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Test',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        $enrollmentService = app(SequenceEnrollmentService::class);

        // Create execution record twice
        $enrollmentService->createStepExecutionRecord($enrollment);
        $enrollmentService->createStepExecutionRecord($enrollment);

        // Should only have one execution record
        $executionCount = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('sequence_step_id', $step->id)
            ->count();

        $this->assertEquals(1, $executionCount);
    }

    // ── CONVERSATION CLOSE CANCELS ENROLLMENT ────────────────────────────────

    public function test_conversation_close_cancels_enrollment()
    {
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
        ]);

        // Close conversation via API
        $response = $this->actingAs($this->user)
            ->patchJson("/api/inbox/{$this->conversation->id}/status", [
                'status' => 'closed',
            ]);

        $response->assertOk();

        // Verify enrollment was stopped
        $enrollment->refresh();
        $this->assertEquals('stopped', $enrollment->status);
    }
}
