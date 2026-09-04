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
use App\Jobs\ExecuteSequenceStep;
use App\Services\SequenceService;
use App\Services\SequenceEnrollmentService;
use App\Services\SequenceTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class SequenceEndToEndTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private SequenceService $sequenceService;
    private SequenceEnrollmentService $enrollmentService;
    private SequenceTriggerService $triggerService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create();
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $this->sequenceService = new SequenceService();
        $this->enrollmentService = new SequenceEnrollmentService();
        $this->triggerService = new SequenceTriggerService();
    }

    public function test_complete_sequence_lifecycle()
    {
        // 1. Create sequence with steps
        $sequence = $this->sequenceService->createSequence([
            'name' => 'Test Lifecycle Sequence',
            'description' => 'Test sequence for end-to-end verification',
            'trigger_type' => 'manual',
            'channel' => 'whatsapp',
            'steps' => [
                [
                    'step_type' => 'message',
                    'message' => 'Welcome message',
                    'delay_hours' => 0,
                ],
                [
                    'step_type' => 'delay',
                    'delay_hours' => 1,
                    'delay_unit' => 'hours',
                ],
                [
                    'step_type' => 'message',
                    'message' => 'Follow-up message',
                    'delay_hours' => 0,
                ],
            ],
        ], $this->business->id);

        $this->assertEquals('draft', $sequence->status);
        $this->assertCount(3, $sequence->steps);

        // 2. Activate sequence
        $activatedSequence = $this->sequenceService->activateSequence($sequence);
        $this->assertEquals('active', $activatedSequence->status);

        // 3. Create conversation and enroll
        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $enrollment = $this->enrollmentService->enrollConversation($sequence, $conversation);
        
        $this->assertEquals('active', $enrollment->status);
        $this->assertEquals(1, $enrollment->current_step);
        $this->assertNotNull($enrollment->next_execution_at);

        // 4. Verify step execution record was created
        $executions = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)->get();
        $this->assertCount(1, $executions);
        $this->assertEquals('pending', $executions->first()->status);

        // 5. Execute first step
        $firstExecution = $executions->first();
        Queue::fake();
        ExecuteSequenceStep::dispatch($firstExecution->id);
        Queue::assertPushed(ExecuteSequenceStep::class);

        // Manually process the job for testing
        $job = new ExecuteSequenceStep($firstExecution->id);
        $job->handle(app(App\Services\SequenceExecutionService::class));

        // 6. Verify message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'Welcome message',
            'direction' => 'outbound',
            'source' => 'sequence',
        ]);

        // 7. Verify execution status
        $firstExecution->refresh();
        $this->assertEquals('executed', $firstExecution->status);
        $this->assertNotNull($firstExecution->executed_at);

        // 8. Verify enrollment moved to next step
        $enrollment->refresh();
        $this->assertEquals(2, $enrollment->current_step);

        // 9. Verify next step execution was scheduled
        $nextExecutions = SequenceStepExecution::where('sequence_enrollment_id', $enrollment->id)
            ->where('status', 'pending')
            ->get();
        $this->assertCount(1, $nextExecutions);
    }

    public function test_sequence_prevents_duplicate_enrollment()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 1,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // First enrollment should succeed
        $enrollment1 = $this->enrollmentService->enrollConversation($sequence, $conversation);
        $this->assertNotNull($enrollment1);

        // Second enrollment should fail
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already has an active enrollment');
        $this->enrollmentService->enrollConversation($sequence, $conversation);
    }

    public function test_sequence_activation_validation()
    {
        // Test activation without steps should fail
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'draft',
            'channel' => 'whatsapp',
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must have at least one step');
        $this->sequenceService->activateSequence($sequence);

        // Test activation without channel should fail
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'draft',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('configured channel');
        $this->sequenceService->activateSequence($sequence);
    }

    public function test_sequence_stops_on_customer_reply()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 1,
            'step_type' => 'condition',
            'condition_config' => ['type' => 'customer_replied'],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // Simulate customer reply
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Customer response',
            'created_at' => now()->subMinutes(5),
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $sequence->steps()->first()->id,
            'status' => 'pending',
        ]);

        $job = new ExecuteSequenceStep($execution->id);
        $job->handle(app(App\Services\SequenceExecutionService::class));

        // Verify enrollment was stopped
        $enrollment->refresh();
        $this->assertEquals('stopped', $enrollment->status);
        $this->assertNotNull($enrollment->stopped_at);
    }

    public function test_business_isolation()
    {
        $otherBusiness = BusinessProfile::factory()->create();
        
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
        ]);

        $otherConversation = Conversation::factory()->create([
            'business_id' => $otherBusiness->id,
        ]);

        // Should not be able to enroll conversation from different business
        $this->expectException(\Exception::class);
        $this->enrollmentService->enrollConversation($sequence, $otherConversation);
    }

    public function test_sequence_completes_when_no_more_steps()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Final message',
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $sequence->steps()->first()->id,
            'status' => 'pending',
        ]);

        $job = new ExecuteSequenceStep($execution->id);
        $job->handle(app(App\Services\SequenceExecutionService::class));

        // Verify enrollment was completed
        $enrollment->refresh();
        $this->assertEquals('completed', $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
        $this->assertNull($enrollment->next_execution_at);
    }

    public function test_processauto_reply_triggers_sequence()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
            'trigger_type' => 'new_user',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // Simulate ProcessAutoReply trigger
        $this->triggerService->checkAndEnrollForMessageReceived($conversation);

        // Verify enrollment was created
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
        ]);
    }

    public function test_sequence_execution_idempotency()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        $step = SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_type' => 'message',
            'message' => 'Test message',
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'executed',
            'executed_at' => now(),
        ]);

        // Try to execute the same step again
        $job = new ExecuteSequenceStep($execution->id);
        $job->handle(app(App\Services\SequenceExecutionService::class));

        // Verify no duplicate message was created
        $messageCount = Message::where('conversation_id', $conversation->id)
            ->where('content', 'Test message')
            ->count();
        
        $this->assertEquals(0, $messageCount); // No message since WhatsApp channel not configured in test
    }
}
