<?php

namespace Tests\Feature;

use App\Events\ConversationUpdated;
use App\Events\MessageReceived;
use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\ConversationTag;
use App\Models\Message;
use App\Models\TeamMember;
use App\Models\User;
use App\Support\InboxBroadcastPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 7 — Inbox + Realtime hardening.
 *
 * Verifies the realtime event contract end to end:
 *  - message.received carries full inbox context (message, conversation
 *    state, channel identity, bot identity, unread count, agent slot)
 *  - conversation.updated fires on agent assignment, AI toggle, status
 *    change, bot switch and tag changes
 *  - payloads stay under Pusher's 10 KB event limit
 *  - REST state stays consistent with the realtime contract so a
 *    reconnect re-fync can rebuild correct state
 */
class InboxRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private BusinessProfile $business;
    private Channel $channel;
    private Bot $bot;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response(['key' => ['id' => 'msg_123', 'remoteJid' => '+1234567890']], 200),
        ]);

        $this->user = User::factory()->create();
        $this->business = BusinessProfile::factory()->create([
            'user_id' => $this->user->id,
        ]);
        $this->channel = Channel::factory()->create([
            'user_id' => $this->user->id,
            'business_id' => $this->business->id,
            'type' => 'whatsapp',
            'status' => 'connected',
        ]);

        \App\Models\WhatsAppInstance::create([
            'user_id' => $this->user->id,
            'instance_name' => $this->channel->page_id,
            'phone_number' => '201234567890',
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $this->bot = Bot::create([
            'business_profile_id' => $this->business->id,
            'name' => 'Support Bot',
            'status' => 'active',
        ]);
    }

    private function makeConversation(array $attrs = []): Conversation
    {
        return Conversation::factory()->create(array_merge([
            'business_id' => $this->business->id,
            'channel_id' => $this->channel->id,
            'bot_id' => $this->bot->id,
            'sender_id' => '1234567890',
            'sender_name' => 'Ahmad Customer',
            'status' => 'open',
            'ai_enabled' => true,
        ], $attrs));
    }

    // ── message.received contract ─────────────────────────────────────

    public function test_message_received_contract_carries_full_inbox_context()
    {
        $conversation = $this->makeConversation();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'What is the price?',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
        ]);

        $event = new MessageReceived($message, $conversation, $this->user->id);

        // Routed to the channel owner's private inbox channel
        $this->assertEquals(
            ['private-inbox.' . $this->user->id],
            array_map(fn ($c) => $c->name, $event->broadcastOn())
        );
        $this->assertEquals('message.received', $event->broadcastAs());

        $payload = $event->broadcastWith();

        // Message identity + content
        $this->assertEquals($message->id, $payload['message']['id']);
        $this->assertEquals($conversation->id, $payload['message']['conversation_id']);
        $this->assertEquals('What is the price?', $payload['message']['content']);
        $this->assertEquals('inbound', $payload['message']['direction']);
        $this->assertFalse($payload['message']['is_ai']);

        // Conversation state
        $this->assertEquals($conversation->id, $payload['conversation']['id']);
        $this->assertEquals('Ahmad Customer', $payload['conversation']['sender_name']);
        $this->assertEquals('1234567890', $payload['conversation']['sender_id']);
        $this->assertEquals('open', $payload['conversation']['status']);
        $this->assertTrue($payload['conversation']['ai_enabled']);

        // Channel identity (nested + top-level mirror)
        $this->assertEquals($this->channel->id, $payload['conversation']['channel']['id']);
        $this->assertEquals('whatsapp', $payload['conversation']['channel']['type']);
        $this->assertEquals($this->channel->id, $payload['channel']['id']);
        $this->assertEquals('whatsapp', $payload['channel']['type']);

        // Bot identity (nested + top-level mirror)
        $this->assertEquals($this->bot->id, $payload['conversation']['bot']['id']);
        $this->assertEquals('Support Bot', $payload['conversation']['bot']['name']);
        $this->assertEquals($this->bot->id, $payload['bot']['id']);

        // Unread state
        $this->assertEquals(1, $payload['conversation']['unread_count']);

        // Agent assignment slot present (null until assigned)
        $this->assertArrayHasKey('assigned_agent_id', $payload['conversation']);
        $this->assertNull($payload['conversation']['assigned_agent_id']);
    }

    public function test_outbound_ai_message_is_broadcast_for_realtime_delivery()
    {
        $conversation = $this->makeConversation();
        $aiMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => 'Our prices start at $29',
            'direction' => 'outbound',
            'status' => 'sent',
            'is_ai' => true,
        ]);

        Event::fake();

        broadcast(new MessageReceived($aiMessage, $conversation, $this->user->id));

        Event::assertDispatched(MessageReceived::class, function ($event) use ($aiMessage, $conversation) {
            $payload = $event->broadcastWith();

            return $payload['message']['id'] === $aiMessage->id
                && $payload['message']['is_ai'] === true
                && $payload['message']['direction'] === 'outbound'
                && $payload['message']['conversation_id'] === $conversation->id
                && $payload['conversation']['id'] === $conversation->id;
        });
    }

    public function test_manual_reply_broadcasts_message_received_immediately()
    {
        $conversation = $this->makeConversation();

        Event::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/inbox/{$conversation->id}/reply", [
                'message' => 'Thanks for reaching out!',
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(MessageReceived::class, function ($event) use ($conversation) {
            $payload = $event->broadcastWith();

            return $payload['message']['direction'] === 'outbound'
                && $payload['message']['is_ai'] === false
                && $payload['message']['content'] === 'Thanks for reaching out!'
                && $payload['conversation']['id'] === $conversation->id;
        });
    }

    // ── conversation.updated contract ─────────────────────────────────

    public function test_agent_assignment_change_reflects_in_realtime()
    {
        $conversation = $this->makeConversation();
        $agent = User::factory()->create();
        TeamMember::factory()->create([
            'business_id' => $this->business->id,
            'user_id' => $agent->id,
        ]);

        Event::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/conversations/{$conversation->id}/assign", [
                'agent_id' => $agent->id,
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation, $agent) {
            $this->assertEquals(
                ['private-inbox.' . $this->user->id],
                array_map(fn ($c) => $c->name, $event->broadcastOn())
            );
            $this->assertEquals('conversation.updated', $event->broadcastAs());

            $payload = $event->broadcastWith();

            return $payload['conversation']['id'] === $conversation->id
                && $payload['conversation']['assigned_agent_id'] === $agent->id
                && $payload['metadata']['event'] === 'conversation.updated';
        });
    }

    public function test_ai_toggle_reflects_in_realtime()
    {
        $conversation = $this->makeConversation(['ai_enabled' => true]);

        Event::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/inbox/{$conversation->id}/toggle-ai");

        $response->assertStatus(200);
        $this->assertFalse($response->json('ai_enabled'));

        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation) {
            $payload = $event->broadcastWith();

            return $payload['conversation']['id'] === $conversation->id
                && $payload['conversation']['ai_enabled'] === false;
        });
    }

    public function test_status_change_broadcasts_conversation_updated()
    {
        $conversation = $this->makeConversation(['status' => 'open']);

        Event::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/inbox/{$conversation->id}/status", [
                'status' => 'closed',
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation) {
            $payload = $event->broadcastWith();

            return $payload['conversation']['id'] === $conversation->id
                && $payload['conversation']['status'] === 'closed';
        });
    }

    public function test_bot_switch_broadcasts_conversation_updated()
    {
        $conversation = $this->makeConversation();
        $newBot = Bot::create([
            'business_profile_id' => $this->business->id,
            'name' => 'Sales Bot',
            'status' => 'active',
        ]);
        // updateBot only accepts bots assigned to the conversation's channel
        $newBot->channels()->attach($this->channel->id);

        Event::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->patchJson("/api/conversations/{$conversation->id}/bot", [
                'bot_id' => $newBot->id,
            ]);

        $response->assertStatus(200);

        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation, $newBot) {
            $payload = $event->broadcastWith();

            return $payload['conversation']['id'] === $conversation->id
                && $payload['conversation']['bot_id'] === $newBot->id
                && $payload['conversation']['bot']['id'] === $newBot->id
                && $payload['conversation']['bot']['name'] === 'Sales Bot';
        });
    }

    public function test_tag_add_and_remove_broadcast_conversation_updated()
    {
        $conversation = $this->makeConversation();

        Event::fake();

        $id = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/inbox/{$conversation->id}/tags", ['tag' => 'vip']);

        fwrite(STDERR, "TAG STATUS: " . $id->status() . " BODY: " . $id->getContent() . "\n");

        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation) {
            $payload = $event->broadcastWith();

            return $payload['conversation']['id'] === $conversation->id
                && count($payload['tags']) === 1
                && $payload['tags'][0]['tag'] === 'vip';
        });

        Event::fake();

        $tagId = ConversationTag::where('conversation_id', $conversation->id)->first()->id;
        $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/inbox/{$conversation->id}/tags/{$tagId}");

        Event::assertDispatched(ConversationUpdated::class, function ($event) use ($conversation) {
            $payload = $event->broadcastWith();

            return $payload['conversation']['id'] === $conversation->id
                && count($payload['tags']) === 0;
        });
    }

    // ── Unread count ───────────────────────────────────────────────────

    public function test_inbox_list_includes_unread_count()
    {
        $conversation = $this->makeConversation();

        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'created_at' => now(),
        ]);
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'created_at' => now(),
        ]);
        // Outside the 24h unread window
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'created_at' => now()->subHours(25),
        ]);
        // Outbound never counts as unread
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/inbox');

        $response->assertStatus(200);
        $row = collect($response->json('data'))->firstWhere('id', $conversation->id);
        $this->assertNotNull($row);
        $this->assertEquals(2, $row['unread_count']);
    }

    // ── Payload size (Pusher 10 KB limit) ──────────────────────────────

    public function test_oversized_message_payload_stays_within_pusher_limit()
    {
        $conversation = $this->makeConversation();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'content' => str_repeat('a', 50000), // 50 KB raw content
            'direction' => 'inbound',
        ]);

        $payload = (new MessageReceived($message, $conversation, $this->user->id))->broadcastWith();

        // Content is capped at the contract limit
        $this->assertTrue($payload['message']['content_truncated']);
        $this->assertEquals(InboxBroadcastPayload::MAX_CONTENT_LENGTH, mb_strlen($payload['message']['content']));

        // Whole event stays under Pusher's 10 KB per-event limit
        $encoded = json_encode($payload);
        $this->assertLessThan(InboxBroadcastPayload::PUSHER_EVENT_LIMIT_BYTES, strlen($encoded));
    }

    // ── Two open conversations — correct routing ───────────────────────

    public function test_events_for_two_conversations_carry_their_own_identity()
    {
        $convA = $this->makeConversation(['sender_id' => '1111111111', 'sender_name' => 'Customer A']);
        $convB = $this->makeConversation(['sender_id' => '2222222222', 'sender_name' => 'Customer B']);

        $msgA = Message::factory()->create([
            'conversation_id' => $convA->id,
            'direction' => 'inbound',
            'content' => 'Message for A',
        ]);
        $msgB = Message::factory()->create([
            'conversation_id' => $convB->id,
            'direction' => 'inbound',
            'content' => 'Message for B',
        ]);

        $payloadA = (new MessageReceived($msgA, $convA, $this->user->id))->broadcastWith();
        $payloadB = (new MessageReceived($msgB, $convB, $this->user->id))->broadcastWith();

        $this->assertEquals($convA->id, $payloadA['conversation']['id']);
        $this->assertEquals('Customer A', $payloadA['conversation']['sender_name']);
        $this->assertEquals($convB->id, $payloadB['conversation']['id']);
        $this->assertEquals('Customer B', $payloadB['conversation']['sender_name']);
        $this->assertNotEquals($payloadA['message']['id'], $payloadB['message']['id']);
        $this->assertEquals('Message for A', $payloadA['message']['content']);
        $this->assertEquals('Message for B', $payloadB['message']['content']);
    }

    // ── Reconnect re-sync consistency ──────────────────────────────────

    public function test_rest_state_matches_realtime_contract_for_reconnect_resync()
    {
        $conversation = $this->makeConversation();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'content' => 'Latest inbound',
            'created_at' => now(),
        ]);

        // What the realtime event carried…
        $eventPayload = (new MessageReceived($message, $conversation, $this->user->id))->broadcastWith();

        // …must agree with what a reconnect re-fetch returns.
        $listResponse = $this->actingAs($this->user, 'sanctum')->getJson('/api/inbox');
        $row = collect($listResponse->json('data'))->firstWhere('id', $conversation->id);
        $this->assertNotNull($row);
        $this->assertEquals($eventPayload['conversation']['unread_count'], $row['unread_count']);
        $this->assertEquals($eventPayload['conversation']['sender_name'], $row['sender_name']);

        $messagesResponse = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/inbox/{$conversation->id}/messages");
        $this->assertTrue(collect($messagesResponse->json('messages'))->contains('id', $message->id));
    }
}
