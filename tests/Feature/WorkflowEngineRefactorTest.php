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
use App\Models\ContactConsent;
use App\Models\FeatureFlag;
use App\Models\BusinessFeatureFlagAssignment;
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

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create(['user_id' => $this->user->id]);
        $this->user->business_id = $this->business->id;
        $this->user->save();

        $this->channel = Channel::factory()->create([
            'business_id'  => $this->business->id,
            'type'         => 'whatsapp',
            'status'       => 'connected',
            'connected_at' => now(),
            'metadata'     => ['whatsapp_provider' => 'evolution', 'unofficial_risk_accepted' => true],
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

        // Provide a completed enrollment matching the configured condition
        $sequence = Sequence::factory()->create(['business_id' => $this->business->id]);
        SequenceEnrollment::factory()->create([
            'sequence_id'     => $sequence->id,
            'conversation_id' => $conversation->id,
            'status'          => 'completed',
        ]);

        // Update workflow to point at the created sequence
        $triggerConfig = $workflow->trigger_config;
        $triggerConfig['conditions']['sequence_id'] = $sequence->id;
        $workflow->trigger_config = $triggerConfig;
        $workflow->save();

        $engine = app(AutomationEngine::class);
        $results = $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_SEQUENCE_COMPLETED,
            $conversation,
            ['sequence_id' => $sequence->id]
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
        // Results still contain a per-workflow result but with triggered=false
        $this->assertTrue(count($results) > 0);
        foreach ($results as $r) {
            $this->assertFalse($r['triggered']);
        }
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
        $this->assertTrue(count($results2) > 0);
        foreach ($results2 as $r) {
            $this->assertFalse($r['triggered']);
        }

        // Still only ONE workflow execution — the first message triggered, not the second
        $this->assertEquals(1, WorkflowExecution::where('workflow_id', $workflow->id)->where('conversation_id', $conversation->id)->count());
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

        // A distinct source event must not be hidden by a time-window dedupe.
        $nextMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'What is the price again?',
        ]);
        $engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $nextMessage]
        );
        $this->assertSame(2, WorkflowExecution::where('workflow_id', $workflow->id)
            ->where('conversation_id', $conversation->id)->count());
    }

    public function test_keyword_matching_uses_word_boundaries(): void
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['hi'], 'match_type' => 'any']],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'matched_hi']],
        ]);
        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'this is it',
        ]);

        $results = app(AutomationEngine::class)->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertFalse($results[0]['triggered']);
        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
    }

    public function test_message_received_send_message_requires_explicit_conditions(): void
    {
        AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'message_received', 'conditions' => []],
            'actions_config' => [['type' => 'send_message', 'message' => 'Unqualified automatic message']],
        ]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'must-not-send']], 200)]);
        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id' => $this->channel->id,
        ]);
        ContactConsent::create([
            'business_id' => $this->business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($conversation->sender_id, 'whatsapp'),
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'content' => 'hello']);

        $results = app(AutomationEngine::class)->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertNotEmpty($results);
        $this->assertFalse($results[0]['triggered']);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
        $this->assertSame(0, WorkflowExecution::where('conversation_id', $conversation->id)->count());
    }

    public function test_send_message_is_blocked_when_ai_is_disabled_or_human_has_taken_over(): void
    {
        $this->enableWorkflowReplySafety();
        AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['offer'], 'match_type' => 'any']],
            'actions_config' => [['type' => 'send_message', 'message' => 'Offer']],
        ]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'must-not-send']], 200)]);

        foreach ([['ai_enabled' => false, 'requires_human' => false], ['ai_enabled' => true, 'requires_human' => true]] as $state) {
            $conversation = Conversation::factory()->create(array_merge([
                'business_id' => $this->business->id,
                'channel_id' => $this->channel->id,
            ], $state));
            ContactConsent::create([
                'business_id' => $this->business->id,
                'channel' => 'whatsapp',
                'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($conversation->sender_id, 'whatsapp'),
                'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
            ]);
            $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'content' => 'offer']);

            $results = app(AutomationEngine::class)->executeWorkflowsForEvent(
                AutomationEngine::EVENT_MESSAGE_RECEIVED,
                $conversation,
                ['message' => $message]
            );

            $this->assertFalse($results[0]['actions_executed'][0]['success']);
        }
        $this->assertSame(0, Message::where('direction', 'outbound')->where('source', 'automation')->count());
    }

    public function test_workflow_reply_safety_is_globally_enforced(): void
    {
        AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['offer'], 'match_type' => 'any']],
            'actions_config' => [['type' => 'send_message', 'message' => 'Offer']],
        ]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'sent']], 200)]);
        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id' => $this->channel->id,
            'ai_enabled' => true,
            'requires_human' => true,
        ]);
        ContactConsent::create([
            'business_id' => $this->business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($conversation->sender_id, 'whatsapp'),
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
        ]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'content' => 'offer']);

        $results = app(AutomationEngine::class)->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertFalse($results[0]['actions_executed'][0]['success']);
        $this->assertSame(0, Message::where('direction', 'outbound')->where('source', 'automation')->count());
    }

    private function enableWorkflowReplySafety(): void
    {
        $flag = FeatureFlag::where('key', 'phase5_workflow_reply_safety')->firstOrFail();
        BusinessFeatureFlagAssignment::create([
            'business_id' => $this->business->id,
            'feature_flag_id' => $flag->id,
            'enabled' => true,
        ]);
    }

    public function test_workflow_conditions_array_is_evaluated_against_the_trigger_event(): void
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'message_received', 'conditions' => []],
            'conditions' => [['type' => 'message_contains', 'config' => ['value' => 'urgent'], 'operator' => 'and', 'comparison' => 'contains']],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'urgent']],
        ]);
        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'content' => 'urgent please']);

        $results = app(AutomationEngine::class)->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertTrue($results[0]['triggered']);
        $this->assertDatabaseHas('conversation_tags', ['conversation_id' => $conversation->id, 'tag' => 'urgent']);
        $this->assertSame([['type' => 'message_contains', 'config' => ['value' => 'urgent'], 'operator' => 'and', 'comparison' => 'contains']], $workflow->fresh()->conditions);
    }

    public function test_workflow_execution_records_partial_failure_as_failed(): void
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['run'], 'match_type' => 'any']],
            'actions_config' => [
                ['type' => 'add_tag', 'tag' => 'first-action'],
                ['type' => 'unknown_action'],
            ],
        ]);
        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'content' => 'run']);

        app(AutomationEngine::class)->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertDatabaseHas('workflow_executions', [
            'workflow_id' => $workflow->id,
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('conversation_tags', ['conversation_id' => $conversation->id, 'tag' => 'first-action']);
    }

    public function test_workflow_cannot_start_the_sequence_that_retriggers_itself(): void
    {
        $sequence = Sequence::factory()->create(['business_id' => $this->business->id]);
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'sequence_completed', 'conditions' => ['sequence_id' => $sequence->id]],
            'actions_config' => [['type' => 'start_sequence', 'sequence_id' => $sequence->id]],
        ]);
        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $enrollment = SequenceEnrollment::factory()->create([
            'sequence_id' => $sequence->id,
            'conversation_id' => $conversation->id,
            'status' => 'completed',
        ]);

        $results = app(AutomationEngine::class)->executeWorkflowsForEvent(
            AutomationEngine::EVENT_SEQUENCE_COMPLETED,
            $conversation,
            ['sequence_id' => $sequence->id, 'sequence_enrollment_id' => $enrollment->id]
        );

        $this->assertTrue($results[0]['triggered']);
        $this->assertFalse($results[0]['actions_executed'][0]['success']);
        $this->assertSame('workflow_loop_guard_24h', $results[0]['actions_executed'][0]['error']);
        $this->assertSame(1, SequenceEnrollment::where('sequence_id', $sequence->id)->count());
    }

    public function test_workflow_execution_is_capped_at_five_runs_per_conversation_per_hour(): void
    {
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['run'], 'match_type' => 'any']],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'capped']],
        ]);
        $conversation = Conversation::factory()->create(['business_id' => $this->business->id]);
        $engine = app(AutomationEngine::class);

        for ($i = 0; $i < 6; $i++) {
            $message = Message::factory()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'content' => 'run ' . $i,
            ]);
            $results = $engine->executeWorkflowsForEvent(
                AutomationEngine::EVENT_MESSAGE_RECEIVED,
                $conversation,
                ['message' => $message]
            );
        }

        $this->assertSame(5, WorkflowExecution::where('workflow_id', $workflow->id)->count());
        $this->assertFalse($results[0]['triggered']);
    }

    public function test_scheduled_time_workflow_runs_once_in_business_timezone(): void
    {
        $this->business->update(['timezone' => 'America/New_York']);
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => 'time',
                'conditions' => ['time_range' => ['start' => '09:00', 'end' => '17:00']],
            ],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'scheduled']],
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id' => $this->channel->id,
        ]);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 14:00:00', 'UTC'));

        \Illuminate\Support\Facades\Artisan::call('workflows:run-time-triggers');
        \Illuminate\Support\Facades\Artisan::call('workflows:run-time-triggers');

        $this->assertSame(1, WorkflowExecution::where('workflow_id', $workflow->id)->where('conversation_id', $conversation->id)->count());
        $this->assertDatabaseHas('conversation_tags', ['conversation_id' => $conversation->id, 'tag' => 'scheduled']);
    }

    public function test_replay_retries_only_failed_actions_and_rechecks_consent(): void
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'replayed-provider-message']], 200),
            '*' => Http::response([], 200),
        ]);
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['offer'], 'match_type' => 'any']],
            'actions_config' => [
                ['type' => 'add_tag', 'tag' => 'successful-action'],
                ['type' => 'send_message', 'message' => 'Marketing offer'],
            ],
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id' => $this->channel->id,
        ]);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'offer',
        ]);

        app(AutomationEngine::class)->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );
        $source = WorkflowExecution::where('workflow_id', $workflow->id)->firstOrFail();
        $this->assertSame('failed', $source->status);
        $this->assertTrue($source->results['actions_executed'][0]['success']);
        $this->assertFalse($source->results['actions_executed'][1]['success']);

        // Remove the successful side effect. A replay must not execute it again.
        \App\Models\ConversationTag::where('conversation_id', $conversation->id)->where('tag', 'successful-action')->delete();
        ContactConsent::create([
            'business_id' => $this->business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($conversation->sender_id, 'whatsapp'),
            'scope' => 'marketing',
            'status' => 'opted_in',
            'source' => 'form',
            'captured_at' => now(),
        ]);

        $replay = app(AutomationEngine::class)->replayFailedActions($workflow, $source);

        $this->assertTrue($replay['success']);
        $this->assertSame(1, count($replay['results']['actions_executed']));
        $this->assertSame(1, $replay['results']['actions_executed'][0]['action_index']);
        $this->assertDatabaseMissing('conversation_tags', ['conversation_id' => $conversation->id, 'tag' => 'successful-action']);
        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->where('source', 'automation')->count());

        $secondAttempt = app(AutomationEngine::class)->replayFailedActions($workflow, $source);
        $this->assertFalse($secondAttempt['success']);
        $this->assertSame('failed_actions_already_replayed', $secondAttempt['error']);
    }

    // ── BOT SCOPE ────────────────────────────────────────────────────────────

    public function test_bot_scoped_workflow_does_not_fire_for_wrong_bot()
    {
        $bot1 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 1', 'status' => 'active']);
        $bot2 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 2', 'status' => 'active']);

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
        foreach ($results as $r) {
            $this->assertFalse($r['triggered']);
        }
        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
    }

    public function test_global_workflow_fires_for_all_bots()
    {
        $bot1 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 1', 'status' => 'active']);
        $bot2 = Bot::create(['business_profile_id' => $this->business->id, 'name' => 'Bot 2', 'status' => 'active']);

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

        // WhatsApp provider needs a sendText response with key.id to rate success
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'fake-msg-id']], 200),
            '*' => Http::response([], 200),
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id'  => $this->channel->id,
        ]);
        ContactConsent::create([
            'business_id' => $this->business->id, 'channel' => 'whatsapp',
            'contact_identifier' => app(\App\Services\ConsentGate::class)->normalizeIdentifier($conversation->sender_id, 'whatsapp'),
            'scope' => 'marketing', 'status' => 'opted_in', 'source' => 'form', 'captured_at' => now(),
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
        fwrite(STDERR, "send_message result: " . json_encode($results[0]['actions_executed']) . "\n");
        $this->assertTrue($results[0]['actions_executed'][0]['success']);
    }

    public function test_workflow_send_message_is_blocked_without_marketing_consent(): void
    {
        AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => ['type' => 'keyword', 'conditions' => ['keywords' => ['offer'], 'match_type' => 'any']],
            'actions_config' => [['type' => 'send_message', 'message' => 'A marketing offer']],
        ]);
        Http::fake(['*' => Http::response(['key' => ['id' => 'should-not-send']], 200)]);
        $conversation = Conversation::factory()->create(['business_id' => $this->business->id, 'channel_id' => $this->channel->id]);
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'direction' => 'inbound', 'content' => 'offer']);

        $results = app(AutomationEngine::class)->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertNotEmpty($results);
        $this->assertFalse($results[0]['actions_executed'][0]['success']);
        $this->assertStringContainsString('no_consent', $results[0]['actions_executed'][0]['error']);
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
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

        // Simulate a provider failure so the send_message action fails
        Http::fake([
            '*/message/sendText/*' => Http::response(['error' => 'Provider timeout'], 500),
            '*' => Http::response([], 500),
        ]);

        $conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
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
