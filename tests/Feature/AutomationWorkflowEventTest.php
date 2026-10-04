<?php

namespace Tests\Feature;

use App\Models\AutomationWorkflow;
use App\Models\BusinessProfile;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AutomationEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationWorkflowEventTest extends TestCase
{
    use RefreshDatabase;

    private AutomationEngine $engine;
    private BusinessProfile $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(AutomationEngine::class);
        $this->business = BusinessProfile::factory()->create([
            'user_id' => User::factory()->create()->id,
        ]);
    }

    private function makeWorkflow(string $triggerType, array $conditions, array $actions = []): AutomationWorkflow
    {
        return AutomationWorkflow::factory()->create([
            'business_id' => $this->business->id,
            'trigger_config' => [
                'type' => $triggerType,
                'conditions' => $conditions,
            ],
            'actions_config' => $actions ?: [
                ['type' => 'add_tag', 'tag' => 'wf_tag'],
            ],
        ]);
    }

    private function makeConversation(): Conversation
    {
        return Conversation::factory()->create([
            'business_id' => $this->business->id,
        ]);
    }

    // ── Event compatibility filtering ─────────────────────────────────────

    public function test_tag_added_workflow_is_not_evaluated_on_message_received_event()
    {
        $workflow = $this->makeWorkflow('tag_added', ['tags' => ['vip']]);
        $conversation = $this->makeConversation();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'hello',
        ]);

        $this->engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
            ['message' => $message]
        );

        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
        $this->assertDatabaseMissing('conversation_tags', ['conversation_id' => $conversation->id]);
        $this->assertEquals(0, $workflow->fresh()->executions_count);
    }

    public function test_keyword_workflow_is_not_evaluated_on_tag_added_event()
    {
        $workflow = $this->makeWorkflow('keyword', ['keywords' => ['price']]);
        $conversation = $this->makeConversation();
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'what is the price?',
        ]);

        $this->engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_TAG_ADDED,
            $conversation,
            ['tag' => 'vip']
        );

        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
        $this->assertEquals(0, $workflow->fresh()->executions_count);
    }

    public function test_tag_added_event_executes_matching_workflow()
    {
        $workflow = $this->makeWorkflow('tag_added', ['tags' => ['vip', 'whale']]);
        $conversation = $this->makeConversation();

        $results = $this->engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_TAG_ADDED,
            $conversation,
            ['tag' => 'whale']
        );

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['triggered']);
        $this->assertDatabaseHas('workflow_executions', [
            'workflow_id' => $workflow->id,
            'conversation_id' => $conversation->id,
            'status' => 'completed',
        ]);
        $this->assertDatabaseHas('conversation_tags', [
            'conversation_id' => $conversation->id,
            'tag' => 'wf_tag',
        ]);
        $this->assertEquals(1, $workflow->fresh()->executions_count);
    }

    public function test_tag_added_event_does_not_execute_workflow_for_a_different_tag()
    {
        $workflow = $this->makeWorkflow('tag_added', ['tags' => ['vip']]);
        $conversation = $this->makeConversation();

        $results = $this->engine->executeWorkflowsForEvent(
            AutomationEngine::EVENT_TAG_ADDED,
            $conversation,
            ['tag' => 'something_else']
        );

        $this->assertTrue($results[0]['triggered'] === false);
        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
    }

    // ── Keyword trigger evaluates the CURRENT message only ────────────────

    public function test_keyword_trigger_matches_only_the_current_message()
    {
        $workflow = $this->makeWorkflow('keyword', ['keywords' => ['refund']]);
        $conversation = $this->makeConversation();

        // Historical message contains the keyword; the current one does not.
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'I want a refund',
        ]);
        $current = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'thanks, that makes sense',
        ]);

        $results = $this->engine->executeWorkflow(
            $workflow,
            $conversation,
            testMode: false,
            eventType: AutomationEngine::EVENT_MESSAGE_RECEIVED,
            eventContext: ['message' => $current]
        );

        $this->assertFalse($results['triggered']);
        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
    }

    public function test_keyword_trigger_matches_when_current_message_contains_keyword()
    {
        $workflow = $this->makeWorkflow('keyword', ['keywords' => ['refund']]);
        $conversation = $this->makeConversation();

        // Historical message does NOT contain the keyword; the current one does.
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'hello there',
        ]);
        $current = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'I need a REFUND please',
        ]);

        $results = $this->engine->executeWorkflow(
            $workflow,
            $conversation,
            testMode: false,
            eventType: AutomationEngine::EVENT_MESSAGE_RECEIVED,
            eventContext: ['message' => $current]
        );

        $this->assertTrue($results['triggered']);
        $this->assertDatabaseHas('workflow_executions', [
            'workflow_id' => $workflow->id,
            'conversation_id' => $conversation->id,
            'status' => 'completed',
        ]);
    }

    // ── First contact trigger is anchored to the current message ──────────

    public function test_first_contact_fires_when_current_message_is_the_first_inbound()
    {
        $workflow = $this->makeWorkflow('first_contact', []);
        $conversation = $this->makeConversation();

        $first = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'hi',
        ]);
        // A second inbound message arrived meanwhile (e.g. a quick follow-up)
        // and the AI already replied — post-event inbound count is > 1, but
        // THIS message is still the first contact.
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'anyone there?',
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'content' => 'AI reply',
        ]);

        $results = $this->engine->executeWorkflow(
            $workflow,
            $conversation,
            testMode: false,
            eventType: AutomationEngine::EVENT_MESSAGE_RECEIVED,
            eventContext: ['message' => $first]
        );

        $this->assertTrue($results['triggered']);
    }

    public function test_first_contact_does_not_fire_for_later_inbound_messages()
    {
        $workflow = $this->makeWorkflow('first_contact', []);
        $conversation = $this->makeConversation();

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'hi',
        ]);
        $second = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'second message',
        ]);

        $results = $this->engine->executeWorkflow(
            $workflow,
            $conversation,
            testMode: false,
            eventType: AutomationEngine::EVENT_MESSAGE_RECEIVED,
            eventContext: ['message' => $second]
        );

        $this->assertFalse($results['triggered']);
        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
    }

    // ── WorkflowExecution lifecycle ────────────────────────────────────────

    public function test_no_workflow_execution_record_is_created_when_trigger_does_not_match()
    {
        $workflow = $this->makeWorkflow('keyword', ['keywords' => ['price']]);
        $conversation = $this->makeConversation();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Hello there',
        ]);

        $results = $this->engine->executeWorkflow(
            $workflow,
            $conversation,
            testMode: false,
            eventType: AutomationEngine::EVENT_MESSAGE_RECEIVED,
            eventContext: ['message' => $message]
        );

        $this->assertFalse($results['triggered']);
        $this->assertDatabaseMissing('workflow_executions', ['workflow_id' => $workflow->id]);
        $this->assertEquals(0, $workflow->fresh()->executions_count);
    }
}
