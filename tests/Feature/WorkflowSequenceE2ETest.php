<?php

namespace Tests\Feature;

use App\Models\AutomationWorkflow;
use App\Models\WorkflowExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\Sequence;
use App\Models\SequenceStep;
use App\Models\SequenceEnrollment;
use App\Models\SequenceStepExecution;
use App\Models\ConversationTag;
use App\Services\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowSequenceE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private User $otherUser;
    private BusinessProfile $otherBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $this->otherUser = User::factory()->create();
        $this->otherBusiness = BusinessProfile::factory()->create([
            'user_id' => $this->otherUser->id,
        ]);
    }

    /**
     * Complete E2E test: incoming message → workflow → tag → message → sequence enrollment → sequence execution
     */
    public function test_complete_workflow_to_sequence_journey()
    {
        // Create a test sequence
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'name' => 'Price Follow-up Sequence',
            'status' => 'active',
            'trigger_type' => 'manual',
        ]);

        // Add steps to the sequence
        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 1,
            'step_type' => 'message',
            'message' => 'Thanks for asking about our pricing!',
            'delay_minutes' => 0,
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 2,
            'step_type' => 'delay',
            'delay_minutes' => 60,
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 3,
            'step_type' => 'message',
            'message' => 'Let me know if you have any other questions!',
            'delay_minutes' => 0,
        ]);

        // Create workflow triggered by "price" keyword
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'name' => 'Price Inquiry Workflow',
            'active' => true,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['price'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'price-question',
                ],
                [
                    'type' => 'send_message',
                    'message' => 'I\'ll help you with the price',
                ],
                [
                    'type' => 'start_sequence',
                    'sequence_id' => $sequence->id,
                ],
            ],
        ]);

        // Create conversation with matching message
        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the price?',
        ]);

        // Execute workflow
        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflow($workflow, $conversation, testMode: false);

        // Verify workflow executed
        $this->assertTrue($results['triggered']);
        $this->assertCount(3, $results['actions_executed'], 'actions_executed: ' . json_encode($results['actions_executed']) . ' errors: ' . json_encode($results['errors'] ?? []));

        // Verify workflow execution record created
        $this->assertDatabaseHas('workflow_executions', [
            'workflow_id' => $workflow->id,
            'conversation_id' => $conversation->id,
            'status' => 'completed',
        ]);

        // Verify tag was added
        $this->assertDatabaseHas('conversation_tags', [
            'conversation_id' => $conversation->id,
            'tag' => 'price-question',
        ]);

        // Verify workflow message was created
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'content' => 'I\'ll help you with the price',
            'direction' => 'outbound',
            'source' => 'automation',
        ]);

        // Verify sequence enrollment created
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
        ]);

        // Verify first step execution was created
        $enrollment = SequenceEnrollment::where('sequence_id', $sequence->id)
            ->where('conversation_id', $conversation->id)
            ->first();

        $this->assertNotNull($enrollment);
        $this->assertEquals(1, $enrollment->current_step);

        // Verify sequence step execution record exists
        $this->assertDatabaseHas('sequence_step_executions', [
            'sequence_id' => $sequence->id,
            'enrollment_id' => $enrollment->id,
            'step_order' => 1,
        ]);

        // Verify workflow execution count incremented
        $workflow->refresh();
        $this->assertEquals(1, $workflow->executions_count);
        $this->assertNotNull($workflow->last_executed_at);
    }

    /**
     * Negative test: message without trigger keyword should not execute workflow
     */
    public function test_non_matching_message_does_not_trigger_workflow()
    {
        $sequence = Sequence::factory()->create([
            'business_id' => $this->business->id,
            'status' => 'active',
        ]);

        SequenceStep::factory()->create([
            'sequence_id' => $sequence->id,
            'step_order' => 1,
        ]);

        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['price'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'price-question',
                ],
                [
                    'type' => 'start_sequence',
                    'sequence_id' => $sequence->id,
                ],
            ],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Hello there',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflow($workflow, $conversation, testMode: false);

        // Verify workflow did not trigger
        $this->assertFalse($results['triggered']);
        $this->assertEmpty($results['actions_executed']);

        // Verify tag was NOT added
        $this->assertDatabaseMissing('conversation_tags', [
            'conversation_id' => $conversation->id,
            'tag' => 'price-question',
        ]);

        // Verify sequence was NOT enrolled
        $this->assertDatabaseMissing('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
        ]);

        // Verify workflow execution count NOT incremented
        $workflow->refresh();
        $this->assertEquals(0, $workflow->executions_count);
    }

    /**
     * Tenant isolation test: Business A workflow should not affect Business B
     */
    public function test_tenant_isolation_workflows()
    {
        // Create Business A workflow
        $workflowA = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['price'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'business-a-tag',
                ],
            ],
        ]);

        // Create Business B workflow
        $workflowB = AutomationWorkflow::factory()->create([
            'user_id' => $this->otherUser->id,
            'business_id' => $this->otherBusiness->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['price'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'business-b-tag',
                ],
            ],
        ]);

        // Create Business A conversation with trigger
        $conversationA = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        Message::factory()->create([
            'conversation_id' => $conversationA->id,
            'direction' => 'inbound',
            'content' => 'What is the price?',
        ]);

        // Execute Business A workflow
        $engine = app(AutomationEngine::class);
        $engine->executeWorkflow($workflowA, $conversationA, testMode: false);

        // Verify Business A tag added
        $this->assertDatabaseHas('conversation_tags', [
            'conversation_id' => $conversationA->id,
            'tag' => 'business-a-tag',
        ]);

        // Verify Business B tag NOT added
        $this->assertDatabaseMissing('conversation_tags', [
            'tag' => 'business-b-tag',
        ]);

        // Verify only Business A workflow execution exists
        $this->assertDatabaseHas('workflow_executions', [
            'workflow_id' => $workflowA->id,
            'business_id' => $this->business->id,
        ]);

        $this->assertDatabaseMissing('workflow_executions', [
            'workflow_id' => $workflowB->id,
        ]);
    }

    /**
     * Test workflow with first_contact trigger
     */
    public function test_first_contact_trigger_workflow()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'first_contact',
                'conditions' => [],
            ],
            'actions_config' => [
                [
                    'type' => 'add_tag',
                    'tag' => 'new-customer',
                ],
            ],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // First message should trigger
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Hello',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflow($workflow, $conversation, testMode: false);

        $this->assertTrue($results['triggered']);
        $this->assertDatabaseHas('conversation_tags', [
            'conversation_id' => $conversation->id,
            'tag' => 'new-customer',
        ]);
    }

    /**
     * Test workflow with tag_added trigger
     */
    public function test_tag_added_trigger_workflow()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'tag_added',
                'conditions' => [
                    'tags' => ['vip'],
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'send_message',
                    'message' => 'Welcome VIP customer!',
                ],
            ],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);

        // Add the tag
        ConversationTag::create([
            'conversation_id' => $conversation->id,
            'tag' => 'vip',
            'source' => 'manual',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflow($workflow, $conversation, testMode: false);

        $this->assertTrue($results['triggered']);
    }

    /**
     * Test stop_sequence action
     */
    public function test_stop_sequence_action()
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

        // Enroll in sequence
        $enrollment = SequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'active',
            'current_step' => 1,
            'started_at' => now(),
        ]);

        // Create workflow to stop sequence
        $workflow = AutomationWorkflow::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => [
                    'keywords' => ['cancel'],
                    'match_type' => 'any',
                ],
            ],
            'actions_config' => [
                [
                    'type' => 'stop_sequence',
                    'sequence_id' => $sequence->id,
                ],
            ],
        ]);

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'I want to cancel',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflow($workflow, $conversation, testMode: false);

        $this->assertTrue($results['triggered']);

        // Verify enrollment was stopped
        $enrollment->refresh();
        $this->assertEquals('stopped', $enrollment->status);
        $this->assertEquals('workflow_action', $enrollment->stop_reason);
    }
}
