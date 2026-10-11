<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\ContactConsent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PublicApiMessageDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_api_message_sends_inside_meta_service_window_and_records_provider_result(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'meta-message-1'], 200)]);
        [$secret, $conversation] = $this->makeApiConversation();
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'created_at' => now()->subMinutes(5),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $secret)->postJson('/api/public/messages/send', [
            'conversation_id' => $conversation->id,
            'message' => 'Hello from the merchant',
        ])->assertOk()->assertJsonPath('status', 'sent');

        $message = Message::findOrFail($response->json('message_id'));
        $this->assertSame('sent', $message->send_status);
        $this->assertSame('meta-message-1', data_get($message->metadata, 'platform_message_id'));
    }

    public function test_public_api_refuses_sends_without_service_window_or_after_opt_out(): void
    {
        [$secret, $conversation] = $this->makeApiConversation();
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'inbound',
            'created_at' => now()->subHours(25),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $secret)->postJson('/api/public/messages/send', [
            'conversation_id' => $conversation->id,
            'message' => 'Too late',
        ])->assertStatus(403)->assertJsonPath('error_code', 'OUTSIDE_WINDOW');
        $this->assertSame(0, $conversation->messages()->where('direction', 'outbound')->count());

        ContactConsent::create([
            'business_id' => $conversation->business_id,
            'channel' => 'facebook',
            'contact_identifier' => $conversation->sender_id,
            'scope' => 'service',
            'status' => 'opted_out',
            'source' => 'test',
        ]);
        $this->withHeader('Authorization', 'Bearer ' . $secret)->postJson('/api/public/messages/send', [
            'conversation_id' => $conversation->id,
            'message' => 'Do not send',
        ])->assertStatus(403)->assertJsonPath('error_code', 'CONSENT_REQUIRED');
        $this->assertSame(0, $conversation->messages()->where('direction', 'outbound')->count());
    }

    private function makeApiConversation(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'access_token' => 'test-page-token',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => 'facebook-contact-123',
        ]);
        $secret = 'naz_live_public-api-test-secret';
        ApiKey::create([
            'user_id' => $user->id,
            'key' => hash('sha256', $secret),
            'secret_hash' => hash('sha256', $secret),
            'key_last_four' => substr($secret, -4),
            'name' => 'test integration',
            'permissions' => ['messages:write'],
            'is_active' => true,
        ]);

        return [$secret, $conversation];
    }
}
