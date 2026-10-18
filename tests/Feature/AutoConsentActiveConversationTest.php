<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConsentGate;
use App\Services\InboundConsentKeywordHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoConsentActiveConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_conversation_within_24_hours_is_automatically_allowed(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '+966500000001',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Hello, I want to know about your products',
            'created_at' => now(),
        ]);

        $gate = app(ConsentGate::class);
        $decision = $gate->checkConversation($conversation, 'marketing');

        $this->assertTrue($decision->allowed);
        $this->assertContains($decision->reason, ['opted_in', 'active_conversation']);

        // Verify that ContactConsent was automatically created
        $this->assertDatabaseHas('contact_consents', [
            'business_id' => $business->id,
            'channel' => 'whatsapp',
            'contact_identifier' => '+966500000001',
            'scope' => 'marketing',
            'status' => 'opted_in',
        ]);
    }

    public function test_suppressed_contact_is_never_automatically_opted_in(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '+966500000002',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        // Customer sends STOP
        $stopMsg = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'STOP',
            'created_at' => now(),
        ]);

        app(InboundConsentKeywordHandler::class)->handle($stopMsg);

        $gate = app(ConsentGate::class);
        $decision = $gate->checkConversation($conversation, 'marketing');

        $this->assertFalse($decision->allowed);
        $this->assertEquals('suppressed', $decision->reason);
    }

    public function test_conversation_older_than_24_hours_without_consent_is_blocked(): void
    {
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $channel = Channel::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);

        $conversation = Conversation::create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '+966500000003',
            'status' => 'open',
            'last_message_at' => now()->subHours(48),
            'updated_at' => now()->subHours(48),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Old message',
            'created_at' => now()->subHours(48),
        ]);

        $gate = app(ConsentGate::class);
        $decision = $gate->checkConversation($conversation, 'marketing');

        $this->assertFalse($decision->allowed);
        $this->assertEquals('no_consent', $decision->reason);
    }
}
