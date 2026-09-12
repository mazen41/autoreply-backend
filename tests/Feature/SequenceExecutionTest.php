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
use App\Jobs\ExecuteSequenceStep;
use App\Services\SequenceExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SequenceExecutionTest extends TestCase
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

        // Fake all Evolution API HTTP calls so no test ever hits a real server.
        Http::fake([
            '*/message/sendText/*' => Http::response([
                'key'     => ['id' => 'fake-msg-id'],
                'message' => ['conversation' => 'faked'],
                'status'  => 'PENDING',
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create();
        $this->user->business_id = $this->business->id;
        $this->user->save();

        // Create a connected WhatsApp channel — SequenceExecutionService requires
        // a connected channel of the sequence's channel type.
        $this->channel = Channel::factory()->create([
            'business_id'  => $this->business->id,
            'type'         => 'whatsapp',
            'status'       => 'connected',
            'connected_at' => now(),
        ]);

        $this->sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status'      => 'active',
            'channel'     => 'whatsapp',
        ]);

        // Conversation must reference the channel so the service can resolve it.
        $this->conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
        ]);
    }

    public function test_sequence_lifecycle_with_message_delay_condition()
    {
        Queue::fake();

        // Create a test sequence: Message -> Delay -> Message -> Condition
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Welcome message',
            'delay_hours' => 0,
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 2,
            'step_type'   => 'delay',
            'delay_hours' => 1,
            'delay_unit'  => 'hours',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 3,
            'step_type'   => 'message',
            'message'     => 'Follow-up message',
            'delay_hours' => 0,
        ]);

        SequenceStep::factory()->create([
            'sequence_id'      => $this->sequence->id,
            'step_order'       => 4,
            'step_type'        => 'condition',
            'condition_config' => [
                'type' => 'customer_replied',
            ],
        ]);

        // Enroll conversation
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'       => $this->sequence->id,
            'conversation_id'   => $this->conversation->id,
            'status'            => 'active',
            'current_step'      => 1,
            'started_at'        => now(),
            'next_execution_at' => now(),
        ]);

        // Verify initial state
        $this->assertEquals('active', $enrollment->status);
        $this->assertEquals(1, $enrollment->current_step);
        $this->assertNotNull($enrollment->next_execution_at);

        // Queue the first step execution
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id'             => $this->sequence->id,
            'sequence_enrollment_id'  => $enrollment->id,
            'sequence_step_id'        => $this->sequence->steps()->where('step_order', 1)->first()->id,
            'status'                  => 'pending',
            'scheduled_at'            => now(),
        ]);

        // Execute the step via service (Queue is faked so no real jobs dispatched)
        $service = app(SequenceExecutionService::class);
        $step1   = $this->sequence->steps()->where('step_order', 1)->first();
        $service->executeStep($execution, $enrollment, $step1);

        // After execution, verify message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Welcome message',
            'direction'       => 'outbound',
            'source'          => 'sequence',
        ]);

        // Verify execution record
        $execution->refresh();
        $this->assertEquals('executed', $execution->status);
        $this->assertNotNull($execution->executed_at);

        // Verify enrollment moved to next step
        $enrollment->refresh();
        $this->assertEquals(2, $enrollment->current_step);
    }

    public function test_idempotency_prevents_duplicate_execution()
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
            'status'                 => 'executed',   // already executed
            'executed_at'            => now(),
        ]);

        // Try to execute the same step again — use container so dependency is injected.
        $service = app(SequenceExecutionService::class);

        // Idempotency guard: status is already 'executed', so executeStep returns early
        // and no message step logic runs.
        $service->executeStep($execution, $enrollment, $step);

        // Verify no message was created (execution was skipped)
        $messageCount = Message::where('conversation_id', $this->conversation->id)
            ->where('content', 'Test message')
            ->count();

        $this->assertEquals(0, $messageCount);
    }

    public function test_sequence_stops_on_customer_reply()
    {
        Queue::fake();

        $step = SequenceStep::factory()->create([
            'sequence_id'      => $this->sequence->id,
            'step_order'       => 1,
            'step_type'        => 'condition',
            'condition_config' => [
                'type'     => 'customer_replied',
                'on_true'  => 'stop',
                'on_false' => 'continue',
            ],
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id'     => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status'          => 'active',
            'current_step'    => 1,
            'started_at'      => now()->subHour(),
        ]);

        // Simulate customer reply after enrollment started
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'direction'       => 'inbound',
            'content'         => 'Customer response',
            'created_at'      => now()->subMinutes(5),
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id'            => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id'       => $step->id,
            'status'                 => 'pending',
        ]);

        $service = app(SequenceExecutionService::class);
        $service->executeStep($execution, $enrollment, $step);

        // Verify enrollment was stopped
        $enrollment->refresh();
        $this->assertEquals('stopped', $enrollment->status);
        $this->assertNotNull($enrollment->stopped_at);
    }

    public function test_sequence_completes_when_no_more_steps()
    {
        Queue::fake();

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order'  => 1,
            'step_type'   => 'message',
            'message'     => 'Final message',
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
        $service->executeStep($execution, $enrollment, $step);

        // Verify enrollment was completed
        $enrollment->refresh();
        $this->assertEquals('completed', $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
        $this->assertNull($enrollment->next_execution_at);
    }

    public function test_business_isolation_in_execution()
    {
        Queue::fake();

        $otherBusiness = BusinessProfile::factory()->create();
        $otherConversation = Conversation::factory()->create([
            'business_id' => $otherBusiness->id,
        ]);

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

        $service = app(SequenceExecutionService::class);
        $service->executeStep($execution, $enrollment, $step);

        // Verify message was created for correct business
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content'         => 'Test message',
        ]);

        // Verify no message for other business
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $otherConversation->id,
            'content'         => 'Test message',
        ]);
    }
}
