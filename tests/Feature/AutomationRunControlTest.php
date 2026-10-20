<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BusinessProfile;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\AutomationWorkflow;
use App\Jobs\ProcessAutoReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationRunControlTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_owner_can_pause_and_resume_automations_with_audit_entries(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $owner->update(['business_id' => $business->id]);

        $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->getJson("/api/businesses/{$business->id}/automation-run-control")
            ->assertOk()
            ->assertJsonPath('paused', false);

        $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->putJson("/api/businesses/{$business->id}/automation-run-control", ['paused' => true])
            ->assertOk()
            ->assertJsonPath('paused', true);

        $this->assertNotNull($business->fresh()->automation_paused_at);
        $this->assertDatabaseHas('audit_logs', [
            'business_profile_id' => $business->id,
            'action' => 'automation.kill_switch_enabled',
        ]);

        $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->putJson("/api/businesses/{$business->id}/automation-run-control", ['paused' => false])
            ->assertOk()
            ->assertJsonPath('paused', false);

        $this->assertNull($business->fresh()->automation_paused_at);
        $this->assertDatabaseHas('audit_logs', [
            'business_profile_id' => $business->id,
            'action' => 'automation.kill_switch_disabled',
        ]);
    }

    public function test_other_business_cannot_change_run_control(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create();
        $business->update(['automation_paused_at' => now()]);

        $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->putJson("/api/businesses/{$business->id}/automation-run-control", ['paused' => false])
            ->assertForbidden();

        $this->assertNotNull($business->fresh()->automation_paused_at);
        $this->assertSame(0, AuditLog::where('action', 'automation.kill_switch_disabled')->count());
    }

    public function test_campaign_can_be_paused_and_resumed_with_audit_entries(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'run-control-channel', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $campaign = Campaign::create([
            'business_id' => $business->id, 'channel_id' => $channel->id,
            'name' => 'Scheduled campaign', 'message' => 'hello', 'status' => 'sending',
        ]);

        $this->actingAs($owner)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/businesses/{$business->id}/campaigns/{$campaign->id}/pause")
            ->assertOk()->assertJsonPath('paused', true);
        $this->assertNotNull($campaign->fresh()->paused_at);
        $this->assertDatabaseHas('audit_logs', ['business_profile_id' => $business->id, 'action' => 'automation.campaign_paused']);

        $this->actingAs($owner)->withHeader('X-Business-Id', (string) $business->id)
            ->postJson("/api/businesses/{$business->id}/campaigns/{$campaign->id}/resume")
            ->assertOk()->assertJsonPath('paused', false);
        $this->assertNull($campaign->fresh()->paused_at);
    }

    public function test_global_pause_prevents_automations_from_starting(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id, 'automation_paused_at' => now()]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'workflow-control-channel', 'access_token' => 'test-token', 'status' => 'connected',
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'sender_id' => 'customer-1',
        ]);
        AutomationWorkflow::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'name' => 'On message', 'active' => true,
            'trigger_config' => ['type' => 'first_contact'], 'conditions' => [], 'actions_config' => [['type' => 'add_tag', 'tag' => 'welcome']],
            'executions_count' => 0,
        ]);

        $results = app(\App\Services\AutomationEngine::class)->executeWorkflowsForEvent(
            \App\Services\AutomationEngine::EVENT_MESSAGE_RECEIVED,
            $conversation,
        );

        $this->assertSame([], $results);
        $this->assertDatabaseCount('workflow_executions', 0);
    }

    public function test_global_pause_drops_queued_ai_turn_without_sending_a_late_reply(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id, 'automation_paused_at' => now()]);
        $channel = Channel::create([
            'user_id' => $owner->id, 'business_id' => $business->id, 'type' => 'instagram',
            'page_id' => 'paused-ai-channel', 'access_token' => 'test-token', 'status' => 'connected', 'ai_enabled' => true,
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'sender_id' => 'customer-paused',
        ]);
        $inbound = Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Hi', 'direction' => 'inbound',
            'status' => 'received', 'send_status' => 'received', 'is_ai' => false,
        ]);

        (new ProcessAutoReply($inbound->id))->handle();

        $this->assertSame(1, Message::where('conversation_id', $conversation->id)->count());
        $this->assertSame(0, Message::where('conversation_id', $conversation->id)->where('direction', 'outbound')->count());
    }
}
