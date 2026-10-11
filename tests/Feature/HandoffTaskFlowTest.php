<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\AutomationWorkflow;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\HandoffTask;
use App\Models\Notification;
use App\Models\Message;
use App\Models\WorkflowExecution;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Jobs\ProcessAutoReply;
use App\Services\HandoffTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HandoffTaskFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_escalation_creates_one_open_task_with_sla_and_one_notification(): void
    {
        config(['handoff.email_notifications' => false, 'handoff.sla_minutes' => 20]);
        [$user, $business, $conversation] = $this->conversation();

        $service = app(HandoffTaskService::class);
        $first = $service->openForConversation($conversation, 'customer_requested_human');
        $service->openForConversation($conversation, 'customer_requested_human');

        $this->assertSame(1, HandoffTask::where('conversation_id', $conversation->id)->where('status', 'open')->count());
        $this->assertTrue($first->due_at->between(now()->addMinutes(19), now()->addMinutes(21)));
        $this->assertSame(1, Notification::where('user_id', $user->id)->where('type', 'handoff_task')->count());
    }

    public function test_handoff_creation_fires_escalation_workflow_once(): void
    {
        config(['handoff.email_notifications' => false]);
        [, $business, $conversation] = $this->conversation();
        $workflow = AutomationWorkflow::factory()->create([
            'business_id' => $business->id,
            'trigger_config' => ['type' => 'escalation_triggered', 'conditions' => []],
            'actions_config' => [['type' => 'add_tag', 'tag' => 'needs_human']],
        ]);

        app(HandoffTaskService::class)->openForConversation($conversation, 'customer_requested_human');
        app(HandoffTaskService::class)->openForConversation($conversation, 'customer_requested_human');

        $this->assertSame(1, WorkflowExecution::where('workflow_id', $workflow->id)->where('conversation_id', $conversation->id)->count());
        $this->assertDatabaseHas('conversation_tags', ['conversation_id' => $conversation->id, 'tag' => 'needs_human']);
    }

    public function test_customer_request_for_a_human_creates_task_before_sending_the_promise(): void
    {
        config(['handoff.email_notifications' => false, 'features.fail_closed_no_agent' => false]);
        Package::create([
            'name' => 'Free', 'name_ar' => 'Free', 'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => -1, 'is_active' => true,
        ]);
        [$user, $business, $conversation] = $this->conversation();
        $channel = $conversation->channel;
        $channel->update(['ai_enabled' => true]);
        $conversation->update(['ai_enabled' => true]);
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Human Request Test Agent',
            'status' => 'active',
            'ai_provider' => 'groq',
        ]);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        $conversation->update(['bot_id' => $bot->id]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'I want to talk to a human agent',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'test-message'], 200), '*' => Http::response([], 200)]);

        (new ProcessAutoReply($message->id))->handle();

        $task = HandoffTask::where('conversation_id', $conversation->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('open', $task->status);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'source' => 'escalation',
            'content' => 'Sure 👍 I\'m connecting you with a human agent now. Please wait a moment.',
        ]);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type' => 'handoff_task',
        ]);
    }

    public function test_agent_keyword_and_confidence_configuration_drives_runtime_escalation(): void
    {
        config(['handoff.email_notifications' => false, 'features.fail_closed_no_agent' => false]);
        Package::create([
            'name' => 'Free', 'name_ar' => 'Free', 'price_monthly' => 0, 'price_yearly' => 0,
            'ai_replies_limit' => -1, 'is_active' => true,
        ]);
        [, $business, $conversation] = $this->conversation();
        $channel = $conversation->channel;
        $channel->update(['ai_enabled' => true]);
        $conversation->update(['ai_enabled' => true]);
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Configured escalation agent',
            'status' => 'active',
            'ai_provider' => 'groq',
            'ai_model' => 'llama-3.3-70b-versatile',
            'ai_confidence_threshold' => 0.5,
            'escalation_config' => ['keywords' => ['priority refund'], 'intents' => []],
        ]);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        $conversation->update(['bot_id' => $bot->id]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'I need a priority refund update',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);
        Http::fake([
            'api.groq.com/openai/v1/chat/completions' => Http::response(['choices' => [[
                'message' => ['content' => json_encode([
                    'reply' => 'I can help with that.', 'intent' => 'question', 'needs_escalation' => false,
                    'confidence' => 0.95, 'escalation_reason' => 'none', 'needs_images' => false,
                ])],
            ]]], 200),
            'graph.facebook.com/*' => Http::response(['message_id' => 'runtime-escalation'], 200),
            '*' => Http::response([], 200),
        ]);

        (new ProcessAutoReply($message->id))->handle();

        $this->assertDatabaseHas('handoff_tasks', [
            'conversation_id' => $conversation->id,
            'status' => 'open',
            'reason' => 'escalation_keyword_match: priority refund',
        ]);
    }

    public function test_business_can_list_and_resolve_its_handoff_task(): void
    {
        config(['handoff.email_notifications' => false]);
        [$user, $business, $conversation] = $this->conversation();
        $task = app(HandoffTaskService::class)->openForConversation($conversation, 'low_confidence');
        $conversation->update(['requires_human' => true, 'status' => 'pending_human']);
        Sanctum::actingAs($user);

        $this->getJson('/api/handoff-tasks', ['X-Business-Id' => (string) $business->id])
            ->assertOk()
            ->assertJsonPath('data.0.id', $task->id)
            ->assertJsonPath('data.0.status', 'open');

        $this->patchJson('/api/handoff-tasks/' . $task->id . '/resolve', ['resolution_note' => 'Refund was reviewed and explained.'], ['X-Business-Id' => (string) $business->id])
            ->assertOk()
            ->assertJsonPath('task.status', 'resolved')
            ->assertJsonPath('task.resolution_note', 'Refund was reviewed and explained.');

        $this->assertFalse((bool) $conversation->fresh()->requires_human);
        $this->assertNotNull($task->fresh()->resolved_at);
    }

    public function test_handoff_list_filters_priority_and_overdue_and_update_saves_priority_and_due_time(): void
    {
        config(['handoff.email_notifications' => false]);
        [$user, $business, $conversation] = $this->conversation();
        $task = app(HandoffTaskService::class)->openForConversation($conversation, 'urgent_case');
        $task->update(['priority' => 'high', 'due_at' => Carbon::now()->subMinute()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/handoff-tasks?status=open&priority=high&overdue=true', ['X-Business-Id' => (string) $business->id])
            ->assertOk()->assertJsonPath('data.0.id', $task->id);
        $newDue = Carbon::now()->addHour()->toIso8601String();
        $this->patchJson('/api/handoff-tasks/' . $task->id, ['priority' => 'high', 'due_at' => $newDue], ['X-Business-Id' => (string) $business->id])
            ->assertOk()->assertJsonPath('task.priority', 'high');
        $this->assertTrue($task->fresh()->due_at->greaterThan(now()));
        $this->getJson('/api/handoff-tasks?status=open&priority=low', ['X-Business-Id' => (string) $business->id])
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_handoff_task_endpoints_hide_other_business_tasks(): void
    {
        [$user, $business, $conversation] = $this->conversation();
        $task = app(HandoffTaskService::class)->openForConversation($conversation, 'complaint');
        $other = User::factory()->create();
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => $other->id]);
        Sanctum::actingAs($other);

        $this->getJson('/api/handoff-tasks', ['X-Business-Id' => (string) $otherBusiness->id])
            ->assertOk()
            ->assertJsonMissing(['id' => $task->id]);
        $this->patchJson('/api/handoff-tasks/' . $task->id . '/resolve', [], ['X-Business-Id' => (string) $otherBusiness->id])
            ->assertNotFound();
    }

    private function conversation(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'ai_enabled' => false,
        ]);
        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => 'customer-' . $user->id,
            'sender_name' => 'Test Customer',
            'status' => 'pending_human',
            'requires_human' => true,
            'ai_enabled' => false,
        ]);

        return [$user, $business, $conversation];
    }
}
