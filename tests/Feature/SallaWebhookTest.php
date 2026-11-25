<?php

namespace Tests\Feature;

use App\Jobs\SallaWebhookJob;
use App\Services\SallaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
