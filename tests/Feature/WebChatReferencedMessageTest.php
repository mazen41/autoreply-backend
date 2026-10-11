<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProductMessageMap;
use App\Models\User;
use App\Models\WebChatSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WebChatReferencedMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_webchat_reference_is_normalized_only_from_same_conversation(): void
    {
        Event::fake();
        $user = User::forceCreate([
            'name' => 'Web Chat Test',
            'email' => 'web-chat-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
        ]);
        $business = BusinessProfile::create([
            'user_id' => $user->id,
            'business_name' => 'Web Chat Store',
            'ai_enabled' => false,
        ]);
        $store = Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test-token',
        ]);
        $session = WebChatSession::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'session_id' => 'web-session-' . uniqid(),
            'visitor_name' => 'Visitor',
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id,
            'web_chat_session_id' => $session->id,
            'ecommerce_connection_id' => $store->id,
            'sender_id' => $session->session_id,
            'status' => 'open',
        ]);
        $conversation->forceFill(['web_chat_session_id' => $session->id])->save();
        $productImage = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Blue Shirt',
            'direction' => 'outbound',
            'status' => 'sent',
            'metadata' => ['commerce_product_id' => 'shirt-1'],
        ]);
        ProductMessageMap::create([
            'conversation_id' => $conversation->id,
            'channel_id' => null,
            'provider' => 'web_chat',
            'commerce_provider' => 'salla',
            'internal_message_id' => $productImage->id,
            'platform_message_id' => null,
            'commerce_channel_id' => $store->id,
            'commerce_external_id' => 'shirt-1',
            'salla_product_id' => 'shirt-1',
            'product_name' => 'Blue Shirt',
            'product_price' => '75',
            'currency' => 'SAR',
            'sent_at' => now(),
        ]);
        $this->mock(\App\Services\NotificationService::class)
            ->shouldReceive('newMessage')->once();

        $this->actingAs($user);
        $response = $this->postJson('/api/web-chat/messages', [
            'session_id' => $session->session_id,
            'message' => 'I want to order this',
            'referenced_message_id' => (string) $productImage->id,
        ]);
        $response->assertOk();
        $incoming = Message::where('conversation_id', $conversation->id)->where('direction', 'inbound')->firstOrFail();
        $this->assertSame('internal:' . $productImage->id, $incoming->metadata['referenced_platform_message_id']);
    }
}
