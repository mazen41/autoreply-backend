<?php

namespace Tests\Feature;

use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsAppCloudTemplate;
use App\Services\WhatsAppCloudApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsAppCloudApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_cloud_api_text_send_uses_channel_credentials_and_phone_number_id(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.test']]], 200)]);
        config(['services.meta.graph_version' => 'v26.0']);

        $business = BusinessProfile::factory()->create();
        $channel = Channel::create([
            'user_id' => $business->user_id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'phone-number-123',
            'page_name' => 'Support Number',
            'access_token' => 'cloud-secret-token',
            'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-456'],
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => '201155555555',
        ]);

        $result = app(WhatsAppCloudApiService::class)->sendText($channel, $conversation->sender_id, 'Hello');

        $this->assertTrue($result['success']);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v26.0/phone-number-123/messages'
                && $request->hasHeader('Authorization', 'Bearer cloud-secret-token')
                && $request['messaging_product'] === 'whatsapp'
                && $request['to'] === '201155555555'
                && $request['type'] === 'text';
        });
    }

    public function test_cloud_image_send_uses_link_payload(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.image']]], 200)]);
        $business = BusinessProfile::factory()->create();
        $channel = Channel::create([
            'user_id' => $business->user_id, 'business_id' => $business->id,
            'type' => 'whatsapp', 'page_id' => 'phone-number-123',
            'access_token' => 'cloud-secret-token', 'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-456'],
        ]);

        $result = app(WhatsAppCloudApiService::class)->sendImage(
            $channel, '201155555555', 'https://cdn.example.test/product.jpg', 'Product photo'
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($request) => $request['type'] === 'image'
            && $request['image']['link'] === 'https://cdn.example.test/product.jpg'
            && $request['image']['caption'] === 'Product photo');
    }

    public function test_cloud_connection_verifies_meta_assets_and_never_returns_the_token(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\PlanEnforcement::class);
        Http::fake([
            'graph.facebook.com/v26.0/phone-number-123*' => Http::response([
                'id' => 'phone-number-123',
                'display_phone_number' => '+1 555 123 4567',
                'verified_name' => 'Support',
            ], 200),
            'graph.facebook.com/v26.0/waba-456/phone_numbers*' => Http::response(['data' => [['id' => 'phone-number-123']]], 200),
            'graph.facebook.com/v26.0/waba-456/subscribed_apps' => Http::response(['success' => true], 200),
        ]);
        config(['services.meta.graph_version' => 'v26.0']);

        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);
        $response = $this->actingAs($owner)
            ->withHeader('X-Business-Id', (string) $business->id)
            ->postJson('/api/channels/whatsapp/cloud', [
                'phone_number_id' => 'phone-number-123',
                'business_account_id' => 'waba-456',
                'access_token' => 'cloud-secret-token-123',
                'name' => 'Support',
            ]);

        $response->assertCreated()
            ->assertJsonMissing(['access_token' => 'cloud-secret-token-123'])
            ->assertJsonPath('channel.metadata.whatsapp_provider', 'cloud')
            ->assertJsonPath('webhook_subscribed', true);

        $channelId = $response->json('channel.id');
        $channel = Channel::findOrFail($channelId);
        $this->assertSame('cloud-secret-token-123', $channel->access_token);
        $this->assertNotSame('cloud-secret-token-123', $channel->getRawOriginal('access_token'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/waba-456/subscribed_apps'));
    }

    public function test_legacy_qr_connection_requires_explicit_unofficial_risk_acknowledgment(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\PlanEnforcement::class);
        Http::fake();
        $owner = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $owner->id]);

        $response = $this->actingAs($owner)->postJson('/api/whatsapp/connect', [], [
            'X-Business-Id' => (string) $business->id,
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors('unofficial_risk_accepted');
        Http::assertNothingSent();
    }

    public function test_phone_number_id_cannot_be_reconnected_under_another_business(): void
    {
        $this->withoutMiddleware(\App\Http\Middleware\PlanEnforcement::class);
        Http::fake([
            'graph.facebook.com/v26.0/phone-number-shared*' => Http::response([
                'id' => 'phone-number-shared', 'display_phone_number' => '+1 555 000 1111', 'verified_name' => 'Shared',
            ], 200),
            'graph.facebook.com/v26.0/waba-shared/phone_numbers*' => Http::response(['data' => [['id' => 'phone-number-shared']]], 200),
        ]);
        $ownerA = User::factory()->create();
        $businessA = BusinessProfile::factory()->create(['user_id' => $ownerA->id]);
        $ownerB = User::factory()->create();
        $businessB = BusinessProfile::factory()->create(['user_id' => $ownerB->id]);
        $channel = Channel::create([
            'user_id' => $ownerA->id, 'business_id' => $businessA->id, 'type' => 'whatsapp',
            'page_id' => 'phone-number-shared', 'access_token' => 'original-token', 'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-shared'],
        ]);

        $response = $this->actingAs($ownerB)->withHeader('X-Business-Id', (string) $businessB->id)
            ->postJson('/api/channels/whatsapp/cloud', [
                'phone_number_id' => 'phone-number-shared',
                'business_account_id' => 'waba-shared',
                'access_token' => 'attacker-token-with-adequate-length',
            ]);

        $response->assertStatus(409);
        $this->assertSame('original-token', $channel->fresh()->access_token);
        $this->assertSame($businessA->id, $channel->fresh()->business_id);
    }

    public function test_only_an_approved_channel_template_can_be_sent(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.template']]], 200)]);
        $business = BusinessProfile::factory()->create();
        $channel = Channel::create([
            'user_id' => $business->user_id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'phone-number-123',
            'access_token' => 'cloud-secret-token-123',
            'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-456'],
        ]);

        $blocked = app(WhatsAppCloudApiService::class)->sendTemplate(
            $channel,
            '201155555555',
            'order_update',
            'en_US'
        );
        $this->assertFalse($blocked['success']);
        Http::assertNothingSent();

        WhatsAppCloudTemplate::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'name' => 'order_update',
            'language' => 'en_US',
            'category' => 'UTILITY',
            'components' => [['type' => 'BODY', 'text' => 'Order update']],
            'status' => 'APPROVED',
        ]);

        $sent = app(WhatsAppCloudApiService::class)->sendTemplate(
            $channel,
            '201155555555',
            'order_update',
            'en_US',
            [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => '123']]]]
        );

        $this->assertTrue($sent['success']);
        Http::assertSent(fn ($request) => $request['type'] === 'template'
            && $request['template']['name'] === 'order_update'
            && $request['to'] === '201155555555');
    }

    public function test_cloud_free_form_reply_is_blocked_after_the_24_hour_window(): void
    {
        Http::fake();
        $business = BusinessProfile::factory()->create();
        $channel = Channel::create([
            'user_id' => $business->user_id,
            'business_id' => $business->id,
            'type' => 'whatsapp',
            'page_id' => 'phone-number-123',
            'access_token' => 'cloud-secret-token',
            'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-456'],
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => '201155555555',
        ]);
        Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Hello',
            'direction' => 'inbound',
            'status' => 'received',
            'created_at' => now()->subHours(25),
        ]);
        $outbound = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Free form reply',
            'direction' => 'outbound',
            'status' => 'sent',
        ]);

        $sent = app(\App\Services\ChannelMessageService::class)
            ->sendMessage($channel, $conversation, $outbound);

        $this->assertFalse($sent);
        Http::assertNothingSent();
        $this->assertSame('blocked', $outbound->fresh()->send_status);
    }

    public function test_cloud_reply_persists_meta_message_id_inside_customer_care_window(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.reply-1']]], 200)]);
        $business = BusinessProfile::factory()->create();
        $channel = Channel::create([
            'user_id' => $business->user_id, 'business_id' => $business->id,
            'type' => 'whatsapp', 'page_id' => 'phone-number-123',
            'access_token' => 'cloud-secret-token', 'status' => 'connected',
            'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-456'],
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'sender_id' => '201155555555',
        ]);
        Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Hi', 'direction' => 'inbound',
            'status' => 'received', 'created_at' => now()->subMinutes(10),
        ]);
        $outbound = Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Hello', 'direction' => 'outbound', 'status' => 'sent',
        ]);

        $sent = app(\App\Services\ChannelMessageService::class)->sendMessage($channel, $conversation, $outbound);

        $this->assertTrue($sent);
        $this->assertSame('wamid.reply-1', $outbound->fresh()->whatsapp_message_id);
        $this->assertSame('sent', $outbound->fresh()->send_status);
    }

    public function test_meta_webhook_routes_cloud_message_by_phone_number_id(): void
    {
        Queue::fake();
        config(['services.meta.app_secret' => 'test-meta-secret']);
        $business = BusinessProfile::factory()->create();
        $first = Channel::create([
            'user_id' => $business->user_id, 'business_id' => $business->id,
            'type' => 'whatsapp', 'page_id' => 'phone-a', 'access_token' => 'token-a',
            'status' => 'connected', 'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-a'],
        ]);
        Channel::create([
            'user_id' => $business->user_id, 'business_id' => $business->id,
            'type' => 'whatsapp', 'page_id' => 'phone-b', 'access_token' => 'token-b',
            'status' => 'connected', 'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-b'],
        ]);

        $body = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-a',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => 'phone-a'],
                        'contacts' => [['wa_id' => '201155555555', 'profile' => ['name' => 'Test Customer']]],
                        'messages' => [[
                            'from' => '201155555555', 'id' => 'wamid.inbound-1',
                            'type' => 'text', 'text' => ['body' => 'Hi there'],
                        ]],
                    ],
                ]],
            ]],
        ]);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'test-meta-secret');

        $response = $this->call('POST', '/api/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);

        $response->assertOk();
        $conversation = Conversation::where('sender_id', '201155555555')->firstOrFail();
        $this->assertSame($first->id, $conversation->channel_id);
        $this->assertSame('Test Customer', $conversation->sender_name);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => 'wamid.inbound-1',
            'content' => 'Hi there',
        ]);
    }

    public function test_cloud_status_webhook_preserves_read_state_and_records_failure_reason(): void
    {
        config(['services.meta.app_secret' => 'test-meta-secret']);
        $business = BusinessProfile::factory()->create();
        $channel = Channel::create([
            'user_id' => $business->user_id, 'business_id' => $business->id,
            'type' => 'whatsapp', 'page_id' => 'phone-status', 'access_token' => 'token-status',
            'status' => 'connected', 'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-status'],
        ]);
        $conversation = Conversation::create([
            'business_id' => $business->id, 'channel_id' => $channel->id, 'sender_id' => '201155555555',
        ]);
        $readMessage = Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Read me', 'direction' => 'outbound',
            'status' => 'sent', 'send_status' => 'sent', 'delivery_status' => 'sent',
            'whatsapp_message_id' => 'wamid.status-read',
        ]);
        $failedMessage = Message::create([
            'conversation_id' => $conversation->id, 'content' => 'Could not deliver', 'direction' => 'outbound',
            'status' => 'sent', 'send_status' => 'sent', 'delivery_status' => 'sent',
            'whatsapp_message_id' => 'wamid.status-failed',
        ]);

        $sendWebhook = function (array $statuses): void {
            $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
                'id' => 'waba-status', 'changes' => [[
                    'field' => 'messages', 'value' => [
                        'metadata' => ['phone_number_id' => 'phone-status'],
                        'statuses' => $statuses,
                    ],
                ]],
            ]]]);
            $signature = 'sha256=' . hash_hmac('sha256', $body, 'test-meta-secret');
            $this->call('POST', '/api/webhook/meta', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
            ], $body)->assertOk();
        };

        $sendWebhook([
            ['id' => 'wamid.status-read', 'status' => 'read'],
            ['id' => 'wamid.status-failed', 'status' => 'failed', 'errors' => [[
                'code' => 131026, 'title' => 'Message undeliverable', 'message' => 'Recipient unavailable',
            ]]],
        ]);

        $this->assertSame('read', $readMessage->fresh()->send_status);
        $this->assertSame('read', $readMessage->fresh()->status);
        $this->assertSame('delivered', $readMessage->fresh()->delivery_status);
        $this->assertSame('failed', $failedMessage->fresh()->send_status);
        $this->assertSame('Message undeliverable', $failedMessage->fresh()->error_details);

        $sendWebhook([['id' => 'wamid.status-read', 'status' => 'delivered']]);
        $this->assertSame('read', $readMessage->fresh()->send_status, 'An older delivery event must not downgrade a read message.');
    }

    public function test_cloud_inbound_image_is_downloaded_to_private_storage_and_queued_for_analysis(): void
    {
        Queue::fake();
        Storage::fake('private_media');
        config(['services.meta.app_secret' => 'test-meta-secret', 'services.evolution.incoming_media_disk' => 'private_media']);
        Http::fake([
            'graph.facebook.com/v26.0/cloud-media-1' => Http::response([
                'url' => 'https://lookaside.fbsbx.com/media/image-1',
                'mime_type' => 'image/jpeg',
                'file_size' => 7,
            ], 200),
            'lookaside.fbsbx.com/media/image-1' => Http::response('jpegbin', 200),
        ]);
        $business = BusinessProfile::factory()->create();
        $channel = Channel::create([
            'user_id' => $business->user_id, 'business_id' => $business->id,
            'type' => 'whatsapp', 'page_id' => 'phone-media', 'access_token' => 'cloud-token',
            'status' => 'connected', 'metadata' => ['whatsapp_provider' => 'cloud', 'whatsapp_business_account_id' => 'waba-media'],
        ]);
        $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [[
            'id' => 'waba-media', 'changes' => [[
                'field' => 'messages', 'value' => [
                    'metadata' => ['phone_number_id' => 'phone-media'],
                    'messages' => [[
                        'from' => '201155555555', 'id' => 'wamid.image-inbound',
                        'type' => 'image', 'image' => ['id' => 'cloud-media-1', 'caption' => 'Here is the product'],
                    ]],
                ],
            ]],
        ]]]);
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'test-meta-secret');

        $response = $this->call('POST', '/api/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $body);

        $response->assertOk();
        $message = Message::where('whatsapp_message_id', 'wamid.image-inbound')->firstOrFail();
        $this->assertSame($channel->id, $message->conversation->channel_id);
        $this->assertSame('image', $message->type);
        $this->assertSame('pending', $message->media_processing_status);
        $this->assertSame('private_media', data_get($message->metadata, 'media.storage_disk'));
        Storage::disk('private_media')->assertExists($message->media_url);
        Http::assertSent(fn ($request) => $request->url() === 'https://lookaside.fbsbx.com/media/image-1'
            && $request->hasHeader('Authorization', 'Bearer cloud-token'));
    }
}
