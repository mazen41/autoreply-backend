<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Tests\TestCase;

class MetaWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;
    private const APP_SECRET = 'test-meta-app-secret';
    private const WEBHOOK_URI = '/api/webhook/meta';

    protected function setUp(): void
    {
        parent::setUp();

        // Pin the secret — otherwise a locally-configured .env secret is used
        // and signatures computed against the test secret fail.
        config(['services.meta.app_secret' => self::APP_SECRET]);
    }

    private function signedPayload(string $body, ?string $secret = self::APP_SECRET): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret ?? '');
    }

    private function postWebhook(string $rawBody, array $headers)
    {
        return $this->call(
            'POST',
            self::WEBHOOK_URI,
            [],
            [],
            [],
            $this->transformHeadersToServerVars(array_merge([
                'Content-Type' => 'application/json',
            ], $headers)),
            $rawBody
        );
    }

    public function test_valid_signature_is_accepted()
    {
        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [],
        ]);

        $response = $this->postWebhook($rawBody, [
            'X-Hub-Signature-256' => $this->signedPayload($rawBody),
        ]);

        $response->assertStatus(200);
        $response->assertSee('EVENT_RECEIVED');
    }

    public function test_verified_meta_webhook_does_not_log_payload_content(): void
    {
        $privateMarker = 'meta-private-customer-message-marker';
        $rawBody = json_encode([
            'object' => 'unhandled_test_object',
            'entry' => [['private_customer_content' => $privateMarker]],
        ]);

        Log::shouldReceive('info')->zeroOrMoreTimes()->withArgs(function (...$args) use ($privateMarker): bool {
            $this->assertStringNotContainsString(
                $privateMarker,
                json_encode($args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            return true;
        });

        $this->postWebhook($rawBody, [
            'X-Hub-Signature-256' => $this->signedPayload($rawBody),
        ])->assertStatus(200);
    }

    public function test_invalid_signature_is_rejected_with_403()
    {
        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [],
        ]);

        $response = $this->postWebhook($rawBody, [
            'X-Hub-Signature-256' => 'sha256=' . str_repeat('0', 64),
        ]);

        $response->assertStatus(403);
    }

    public function test_signature_over_mutated_body_is_rejected_with_403()
    {
        // Simulates a payload whose body was altered in transit: the
        // signature is valid for DIFFERENT content than what is received.
        $rawBody = json_encode(['object' => 'page', 'entry' => []]);
        $mutatedBody = json_encode(['object' => 'page', 'entry' => [], 'injected' => true]);

        $response = $this->postWebhook($rawBody, [
            'X-Hub-Signature-256' => $this->signedPayload($mutatedBody),
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_signature_is_rejected_when_secret_is_configured()
    {
        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [],
        ]);

        $response = $this->postWebhook($rawBody, []);

        $response->assertStatus(403);
    }

    public function test_webhook_is_rejected_when_no_secret_is_configured()
    {
        config(['services.meta.app_secret' => null]);

        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [],
        ]);

        $response = $this->postWebhook($rawBody, []);

        $response->assertStatus(503);
    }

    public function test_facebook_read_watermark_marks_only_older_outbound_messages_as_read(): void
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'facebook',
            'page_id' => 'page-read-test',
            'status' => 'connected',
        ]);
        $conversation = Conversation::factory()->create([
            'business_id' => $business->id,
            'channel_id' => $channel->id,
            'sender_id' => 'customer-read-test',
        ]);
        $readMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'send_status' => 'sent',
            'delivery_status' => 'sent',
            'metadata' => ['platform_message_id' => 'mid-read'],
            'created_at' => now()->subMinute(),
        ]);
        $laterMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'status' => 'sent',
            'send_status' => 'sent',
            'delivery_status' => 'sent',
            'metadata' => ['platform_message_id' => 'mid-later'],
            'created_at' => now()->addMinute(),
        ]);
        $rawBody = json_encode([
            'object' => 'page',
            'entry' => [[
                'id' => 'page-read-test',
                'messaging' => [[
                    'sender' => ['id' => 'customer-read-test'],
                    'recipient' => ['id' => 'page-read-test'],
                    'timestamp' => now()->valueOf(),
                    'read' => ['watermark' => now()->valueOf()],
                ]],
            ]],
        ]);

        $this->postWebhook($rawBody, [
            'X-Hub-Signature-256' => $this->signedPayload($rawBody),
        ])->assertOk();

        $this->assertSame('read', $readMessage->fresh()->send_status);
        $this->assertSame('delivered', $readMessage->fresh()->delivery_status);
        $this->assertSame('sent', $laterMessage->fresh()->send_status);
    }
}
