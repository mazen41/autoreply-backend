<?php

namespace Tests\Feature;

use App\Models\AutomationWorkflow;
use App\Models\WorkflowExecution;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\BusinessProfile;
use App\Models\User;
use App\Models\Channel;
use App\Models\Bot;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Services\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkflowEngineRefactorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create(['user_id' => $this->user->id]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $this->channel = Channel::factory()->create([
            'business_id'  => $this->business->id,
            'type'         => 'whatsapp',
            'status'       => 'connected',
            'connected_at' => now(),
        ]);
    }

    // ── EVENT-TO-TRIGGER MAPPING ──────────────────────────────────────────────

    public function test_keyword_trigger_fires_on_message_received_event()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'price_inquiry']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);
    }

    public function test_keyword_trigger_does_not_fire_on_tag_added_event()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'price_inquiry']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_TAG_ADDED,
            $conversation,
            ['tag' => 'price']
        );

        // Keyword workflow should not be evaluated for tag_added events
        $this->assertEmpty($results);
    }

    public function test_tag_added_trigger_fires_on_tag_added_event()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'tag_added',
                'conditions' => ['tags' => ['vip']],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'handled']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_TAG_ADDED,
            $conversation,
            ['tag' => 'vip']
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);
    }

    public function test_sequence_completed_trigger_fires_on_sequence_completed_event()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'sequence_completed',
                'conditions' => ['sequence_id' => 1],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'sequence_done']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_SEQUENCE_COMPLETED,
            $conversation,
            ['sequence_id' => 1]
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);
    }

    public function test_order_status_changed_trigger_fires_on_order_status_changed_event()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'order_status_changed',
                'conditions' => ['order_status' => 'completed'],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'order_completed']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_ORDER_STATUS_CHANGED,
            $conversation,
            ['status' => 'completed']
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);
    }

    // ── KEYWORD TRIGGER — CURRENT MESSAGE ONLY ───────────────────────────────

    public function test_keyword_trigger_does_not_react_to_old_messages()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'price_inquiry']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);

        // Old message with keyword
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
            'created_at'      => now()->subHours(2),
        ]);

        // New message WITHOUT keyword
        $newMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'Hello, I have a question',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $newMessage]
        );

        // Should NOT trigger — the new message doesn't contain the keyword
        $this->assertEmpty($results);
    }

    // ── FIRST CONTACT ────────────────────────────────────────────────────────

    public function test_first_contact_fires_exactly_once_per_conversation()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'first_contact',
                'conditions' => [],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'new_customer']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);

        // First inbound message
        $firstMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'Hello',
        ]);

        $engine = app(AutomationEngine::class);

        // First event — should trigger
        $results1 = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $firstMessage]
        );
        $this->assertNotEmpty($results1);
        $this->assertTrue($results1[0]['triggered']);

        // Second inbound message — should NOT trigger (not first contact anymore)
        $secondMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'Another message',
        ]);

        $results2 = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $secondMessage]
        );
        $this->assertEmpty($results2);
    }

    // ── DEDUPLICATION ────────────────────────────────────────────────────────

    public function test_duplicate_event_does_not_create_duplicate_execution()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'price_inquiry']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);

        // First execution
        $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        // Second execution (duplicate) — should be skipped
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        // Only one execution should exist
        $executionCount = WorkflowExecution::where('workflow_id', $workflow->id)
            ->where('conversation_id', $conversation->id)
            ->count();
        $this->assertEquals(1, $executionCount);
    }

    // ── BOT SCOPE ────────────────────────────────────────────────────────────

    public function test_bot_scoped_workflow_does_not_fire_for_wrong_bot()
    {
        $bot1 = Bot::factory()->create(['business_profile_id' => $this->business->id]);
        $bot2 = Bot::factory()->create(['business_profile_id' => $this->business->id]);

        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'bot_id'         => $bot1->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'price_inquiry']],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'bot_id'      => $bot2->id,
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        // Should NOT trigger — workflow is for bot1, conversation belongs to bot2
        $this->assertEmpty($results);
    }

    public function test_global_workflow_fires_for_all_bots()
    {
        $bot1 = Bot::factory()->create(['business_profile_id' => $this->business->id]);
        $bot2 = Bot::factory()->create(['business_profile_id' => $this->business->id]);

        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'bot_id'         => null, // global
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'price_inquiry']],
        ]);

        // Conversation for bot1
        $conversation1 = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'bot_id'      => $bot1->id,
        ]);

        // Conversation for bot2
        $conversation2 = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'bot_id'      => $bot2->id,
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation1->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);

        // Should trigger for bot1's conversation
        $results1 = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation1,
            ['message' => $message]
        );
        $this->assertNotEmpty($results1);
        $this->assertTrue($results1[0]['triggered']);

        // Should also trigger for bot2's conversation
        $message2 = Message::factory()->create([
            'conversation_id' => $conversation2->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
        ]);

        $results2 = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation2,
            ['message' => $message2]
        );
        $this->assertNotEmpty($results2);
        $this->assertTrue($results2[0]['triggered']);
    }

    // ── WORKFLOW EXECUTION NOT CREATED FOR NON-MATCHING ──────────────────────

    public function test_workflow_execution_not_created_for_non_matching_workflows()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'price_inquiry']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'Hello there', // No keyword
        ]);

        $engine = app(AutomationEngine::class);
        $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        // No execution should be created
        $this->assertDatabaseMissing('workflow_executions', [
            'workflow_id' => $workflow->id,
        ]);
    }

    // ── ACTION EXECUTION ─────────────────────────────────────────────────────

    public function test_send_message_action_executes_correctly()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [
                ['type' => 'send_message', 'message' => 'Our prices are great!'],
            ],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);
        $this->assertNotEmpty($results[0]['actions_executed']);
        $this->assertTrue($results[0]['actions_executed'][0]['success']);
    }

    public function test_add_tag_action_executes_correctly()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [
                ['type' => 'add_tag', 'tag' => 'price_inquiry'],
            ],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);
        $this->assertDatabaseHas('conversation_tags', [
            'conversation_id' => $conversation->id,
            'tag'             => 'price_inquiry',
        ]);
    }

    public function test_escalate_action_executes_correctly()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['complaint'], 'match_type' => 'any'],
            ],
            'actions_config' => [
                ['type' => 'escalate', 'reason' => 'customer_complaint'],
            ],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'This is a complaint!',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);

        $conversation->refresh();
        $this->assertTrue($conversation->requires_human);
        $this->assertEquals('customer_complaint', $conversation->escalation_reason);
    }

    // ── FAILED ACTION HANDLING ───────────────────────────────────────────────

    public function test_failed_action_recorded_with_error_detail()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'keyword',
                'conditions' => ['keywords' => ['price'], 'match_type' => 'any'],
            ],
            'actions_config' => [
                ['type' => 'send_message', 'message' => 'Test'], // Will fail — no channel
            ],
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            // No channel_id — send_message will fail
        ]);

        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction'       => 'inbound',
            'content'         => 'What is the price?',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);
        $this->assertNotEmpty($results[0]['errors']);
        $this->assertFalse($results[0]['actions_executed'][0]['success']);

        // Execution record exists, reached a terminal state (not stuck
        // in 'running'), and carries the failure detail.
        $execution = WorkflowExecution::where('workflow_id', $workflow->id)
            ->where('conversation_id', $conversation->id)
            ->first();
        $this->assertNotNull($execution);
        $this->assertNotEquals('running', $execution->status);
        $this->assertNotNull($execution->completed_at);

        $recordedResults = $execution->results;
        $this->assertNotEmpty($recordedResults['errors']);
        $this->assertStringContainsString('No channel found', $recordedResults['errors'][0]);
    }

    // ── SEQUENCE COMPLETED EVENT ─────────────────────────────────────────────

    public function test_sequence_completed_event_fires_on_enrollment_completion()
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id'    => $this->business->id,
            'trigger_config' => [
                'type' => 'sequence_completed',
                'conditions' => ['sequence_id' => 1],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'sequence_done']],
        ]);

        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);

        // Create a completed sequence enrollment
        $sequence = Sequence::factory()->create(['business_id' => $this->business->id]);
        SequenceEnrollment::factory()->create([
            'sequence_id'     => $sequence->id,
            'conversation_id' => $conversation->id,
            'status'          => 'completed',
        ]);

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_SEQUENCE_COMPLETED,
            $conversation,
            ['sequence_id' => $sequence->id]
        );

        $this->assertNotEmpty($results);
        $this->assertTrue($results[0]['triggered']);
    }
}
