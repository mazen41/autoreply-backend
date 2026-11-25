<?php

namespace Tests\Feature;

use App\Jobs\SallaWebhookJob;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Services\SallaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SallaWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_rejects_invalid_signature(): void
    {
        Queue::fake();

        $payload = json_encode(['event' => 'order.created', 'data' => []]);
        $invalidSignature = 'invalid_signature';

        $response = $this->call('POST', '/api/salla/webhook', [], [], [], $this->transformHeadersToServerVars([
            'X-Salla-Signature' => $invalidSignature,
            'Content-Type' => 'application/json',
        ]), $payload);

        $response->assertStatus(401);
        Queue::assertNotPushed(SallaWebhookJob::class);
    }

    public function test_webhook_accepts_valid_signature(): void
    {
        Queue::fake();
        config(['services.salla.webhook_secret' => 'test_secret']);

        $payload = json_encode(['event' => 'order.created', 'data' => ['id' => 123]]);
        $signature = hash_hmac('sha256', $payload, 'test_secret');

        $response = $this->call('POST', '/api/salla/webhook', [], [], [], $this->transformHeadersToServerVars([
            'X-Salla-Signature' => $signature,
            'Content-Type' => 'application/json',
        ]), $payload);

        $response->assertStatus(200);
        Queue::assertPushed(SallaWebhookJob::class);
    }

    public function test_webhook_dispatches_job_for_order_events(): void
    {
        Queue::fake();
        config(['services.salla.webhook_secret' => 'test_secret']);

        $events = [
            'order.created',
            'order.status.updated',
            'order.shipment.created',
            'order.canceled',
            'order.refunded',
        ];

        foreach ($events as $event) {
            $payload = json_encode(['event' => $event, 'data' => ['id' => rand(1, 1000)]]);
            $signature = hash_hmac('sha256', $payload, 'test_secret');

            $this->call('POST', '/api/salla/webhook', [], [], [], $this->transformHeadersToServerVars([
                'X-Salla-Signature' => $signature,
                'Content-Type' => 'application/json',
            ]), $payload);

            Queue::assertPushed(SallaWebhookJob::class);
        }
    }

    public function test_webhook_dispatches_job_for_customer_events(): void
    {
        Queue::fake();
        config(['services.salla.webhook_secret' => 'test_secret']);

        $events = [
            'customer.created',
            'customer.updated',
        ];

        foreach ($events as $event) {
            $payload = json_encode(['event' => $event, 'data' => ['id' => rand(1, 1000)]]);
            $signature = hash_hmac('sha256', $payload, 'test_secret');

            $this->call('POST', '/api/salla/webhook', [], [], [], $this->transformHeadersToServerVars([
                'X-Salla-Signature' => $signature,
                'Content-Type' => 'application/json',
            ]), $payload);

            Queue::assertPushed(SallaWebhookJob::class);
        }
    }

    public function test_webhook_accepts_token_strategy_with_bearer_header_and_case_insensitive_strategy(): void
    {
        Queue::fake();
        config(['services.salla.webhook_secret' => 'test_secret']);

        $response = $this->call('POST', '/api/salla/webhook', [], [], [], $this->transformHeadersToServerVars([
            'X-Salla-Security-Strategy' => 'tOkEn',
            'Authorization' => 'Bearer test_secret',
            'Content-Type' => 'application/json',
        ]), json_encode(['event' => 'order.created', 'data' => []]));

        $response->assertOk()->assertJsonPath('verification_mode', 'token');
        Queue::assertPushed(SallaWebhookJob::class);
    }

    public function test_webhook_rejects_wrong_token_strategy_credential(): void
    {
        Queue::fake();
        config(['services.salla.webhook_secret' => 'test_secret']);

        $response = $this->call('POST', '/api/salla/webhook', [], [], [], $this->transformHeadersToServerVars([
            'X-Salla-Security-Strategy' => 'Token',
            'Authorization' => 'Bearer wrong_secret',
            'Content-Type' => 'application/json',
        ]), json_encode(['event' => 'order.created', 'data' => []]));

        $response->assertUnauthorized();
        Queue::assertNotPushed(SallaWebhookJob::class);
    }

    public function test_webhook_rejects_token_when_strategy_header_is_missing(): void
    {
        Queue::fake();
        config(['services.salla.webhook_secret' => 'test_secret']);

        $response = $this->call('POST', '/api/salla/webhook', [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => 'Bearer test_secret',
            'Content-Type' => 'application/json',
        ]), json_encode(['event' => 'order.created', 'data' => []]));

        $response->assertUnauthorized();
        Queue::assertNotPushed(SallaWebhookJob::class);
    }

    public function test_app_uninstalled_cleanup_is_safe_when_delivered_twice(): void
    {
        $business = BusinessProfile::factory()->create();
        $channel = Channel::factory()->create([
            'user_id' => $business->user_id,
            'business_id' => $business->id,
            'type' => 'salla',
            'page_id' => 'test-merchant-'.uniqid(),
            'access_token' => 'fake-test-token',
            'refresh_token' => 'fake-test-refresh-token',
            'status' => 'connected',
        ]);
        $conversationId = DB::table('conversations')->insertGetId([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => 'fake-test-sender',
            'sender_name' => 'Test Fixture',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('messages')->insert([
            'conversation_id' => $conversationId,
            'channel_id' => $channel->id,
            'content' => 'Synthetic uninstall fixture',
            'type' => 'text',
            'direction' => 'inbound',
            'status' => 'received',
            'is_ai' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $job = new SallaWebhookJob('app.uninstalled', ['merchant_id' => $channel->page_id]);
        $job->handle();
        $job->handle();

        $this->assertSame('disconnected', $channel->fresh()->status);
        $this->assertNull($channel->fresh()->access_token);
        $this->assertDatabaseMissing('conversations', ['id' => $conversationId]);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversationId]);
    }
}
