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
use App\Services\SequenceExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SequenceExecutionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private Sequence $sequence;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create();
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $this->sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
            'channel' => 'whatsapp',
        ]);

        $this->conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);
    }

    public function test_sequence_lifecycle_with_message_delay_condition()
    {
        // Create a test sequence: Message -> Delay -> Message -> Condition
        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Welcome message',
            'delay_hours' => 0,
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 2,
            'step_type' => 'delay',
            'delay_hours' => 1,
            'delay_unit' => 'hours',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 3,
            'step_type' => 'message',
            'message' => 'Follow-up message',
            'delay_hours' => 0,
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 4,
            'step_type' => 'condition',
            'condition_config' => [
                'type' => 'customer_replied',
            ],
        ]);

        // Enroll conversation
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
            'started_at' => now(),
            'next_execution_at' => now(),
        ]);

        // Verify initial state
        $this->assertEquals('active', $enrollment->status);
        $this->assertEquals(1, $enrollment->current_step);
        $this->assertNotNull($enrollment->next_execution_at);

        // Queue the first step execution
        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $this->sequence->steps()->where('step_order', 1)->first()->id,
            'status' => 'pending',
            'scheduled_at' => now(),
        ]);

        // Execute the step
        Queue::fake();
        ExecuteSequenceStep::dispatch($execution->id);
        Queue::assertPushed(ExecuteSequenceStep::class);

        // After execution, verify message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content' => 'Welcome message',
            'direction' => 'outbound',
            'source' => 'sequence',
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
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Test message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'executed',
            'executed_at' => now(),
        ]);

        // Try to execute the same step again
        $service = new SequenceExecutionService();
        
        // This should not create a duplicate message
        $service->executeStep($execution, $enrollment, $step);

        // Verify only one message exists
        $messageCount = Message::where('conversation_id', $this->conversation->id)
            ->where('content', 'Test message')
            ->count();
        
        $this->assertEquals(1, $messageCount);
    }

    public function test_sequence_stops_on_customer_reply()
    {
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'condition',
            'condition_config' => [
                'type' => 'customer_replied',
            ],
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);

        // Simulate customer reply
        Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'inbound',
            'content' => 'Customer response',
            'created_at' => now()->subMinutes(5),
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);

        $service = new SequenceExecutionService();
        $service->executeStep($execution, $enrollment, $step);

        // Verify enrollment was stopped
        $enrollment->refresh();
        $this->assertEquals('stopped', $enrollment->status);
        $this->assertNotNull($enrollment->stopped_at);
    }

    public function test_sequence_completes_when_no_more_steps()
    {
        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Final message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);

        $service = new SequenceExecutionService();
        $service->executeStep($execution, $enrollment, $step);

        // Verify enrollment was completed
        $enrollment->refresh();
        $this->assertEquals('completed', $enrollment->status);
        $this->assertNotNull($enrollment->completed_at);
        $this->assertNull($enrollment->next_execution_at);
    }

    public function test_business_isolation_in_execution()
    {
        $otherBusiness = BusinessProfile::factory()->create();
        $otherSequence = Sequence::factory()->create([
            'business_id' => $otherBusiness->id,
        ]);

        $otherConversation = Conversation::factory()->create([
            'business_id' => $otherBusiness->id,
        ]);

        $step = SequenceStep::factory()->create([
            'sequence_id' => $this->sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Test message',
        ]);

        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $this->sequence->id,
            'conversation_id' => $this->conversation->id,
            'status' => 'active',
            'current_step' => 1,
        ]);

        $execution = SequenceStepExecution::factory()->create([
            'sequence_id' => $this->sequence->id,
            'sequence_enrollment_id' => $enrollment->id,
            'sequence_step_id' => $step->id,
            'status' => 'pending',
        ]);

        $service = new SequenceExecutionService();
        $service->executeStep($execution, $enrollment, $step);

        // Verify message was created for correct business
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $this->conversation->id,
            'content' => 'Test message',
        ]);

        // Verify no message for other business
        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $otherConversation->id,
            'content' => 'Test message',
        ]);
    }
}
