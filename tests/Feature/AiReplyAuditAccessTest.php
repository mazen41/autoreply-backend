<?php

namespace Tests\Feature;

use App\Models\AiReplyAudit;
use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\AiReplyAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiReplyAuditAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private BusinessProfile $business;
    private Bot $bot;
    private Conversation $conversation;
    private Message $message;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->business = BusinessProfile::factory()->create(['user_id' => $this->owner->id]);
        $channel = Channel::factory()->create([
            'user_id' => $this->owner->id,
            'business_id' => $this->business->id,
            'type' => 'instagram',
            'status' => 'connected',
        ]);
        $this->bot = Bot::create([
            'business_profile_id' => $this->business->id,
            'name' => 'Support Agent',
            'status' => 'active',
        ]);
        $this->conversation = Conversation::factory()->create([
            'business_id' => $this->business->id,
            'channel_id' => $channel->id,
            'bot_id' => $this->bot->id,
            'sender_id' => 'customer-1',
        ]);
        $this->message = Message::factory()->create([
            'conversation_id' => $this->conversation->id,
            'direction' => 'outbound',
            'is_ai' => true,
            'source' => 'ai',
        ]);

        app(AiReplyAuditService::class)->record($this->message, [
            'knowledge_file_ids' => [12],
            'knowledge_chunk_ids' => [34, 35],
            'prompt_version' => 'test-v2',
            'ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash',
            'confidence' => 0.91,
            'escalation_decision' => false,
            'escalation_reason' => 'auto_reply_default',
        ]);
    }

    public function test_business_owner_can_read_ai_reply_explanation(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson("/api/messages/{$this->message->id}/ai-audit");

        $response->assertOk()
            ->assertJsonPath('agent.name', 'Support Agent')
            ->assertJsonPath('knowledge_file_ids.0', 12)
            ->assertJsonPath('knowledge_chunk_ids.1', 35)
            ->assertJsonPath('prompt_version', 'test-v2')
            ->assertJsonPath('ai_model', 'gemini-2.5-flash')
            ->assertJsonPath('consent_basis', 'service_reply_to_customer_inbound');
    }

    public function test_manager_can_read_but_agent_cannot_read_ai_reply_explanation(): void
    {
        $manager = User::factory()->create();
        TeamMember::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $manager->id,
            'role' => 'manager',
            'is_active' => true,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson("/api/messages/{$this->message->id}/ai-audit")
            ->assertOk();

        $agent = User::factory()->create();
        TeamMember::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $agent->id,
            'role' => 'agent',
            'is_active' => true,
        ]);

        $this->actingAs($agent, 'sanctum')
            ->withHeader('X-Business-Id', (string) $this->business->id)
            ->getJson("/api/messages/{$this->message->id}/ai-audit")
            ->assertForbidden();
    }

    public function test_audit_endpoint_conceals_messages_from_another_business(): void
    {
        $otherOwner = User::factory()->create();
        $otherBusiness = BusinessProfile::factory()->create(['user_id' => $otherOwner->id]);

        $this->actingAs($otherOwner, 'sanctum')
            ->withHeader('X-Business-Id', (string) $otherBusiness->id)
            ->getJson("/api/messages/{$this->message->id}/ai-audit")
            ->assertNotFound();
    }

    public function test_audit_records_source_ids_and_never_copies_reply_content(): void
    {
        $audit = AiReplyAudit::where('message_id', $this->message->id)->firstOrFail();

        $this->assertSame([12], $audit->knowledge_file_ids);
        $this->assertSame([34, 35], $audit->knowledge_chunk_ids);
        $this->assertDatabaseMissing('ai_reply_audits', ['content' => $this->message->content]);
    }
}
