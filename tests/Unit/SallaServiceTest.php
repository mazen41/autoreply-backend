<?php

namespace Tests\Unit;

use App\Services\SallaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SallaServiceTest extends TestCase
{
    private SallaService $sallaService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sallaService = new SallaService();
    }

    public function test_get_authorization_url(): void
    {
        config([
            'services.salla.client_id' => 'test_client_id',
            'services.salla.redirect_uri' => 'https://test.com/callback',
        ]);

        $url = $this->sallaService->getAuthorizationUrl('test_state');

        $this->assertStringContainsString('accounts.salla.sa/oauth2/authorize', $url);
        $this->assertStringContainsString('client_id=test_client_id', $url);
        $this->assertStringContainsString('state=test_state', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function test_verify_webhook_signature_valid(): void
    {
        config(['services.salla.webhook_secret' => 'test_secret']);

        $payload = 'test_payload';
        $signature = hash_hmac('sha256', $payload, 'test_secret');

        $result = $this->sallaService->verifyWebhookSignature($payload, $signature);

        $this->assertTrue($result);
    }

    public function test_verify_webhook_signature_invalid(): void
    {
        config(['services.salla.webhook_secret' => 'test_secret']);

        $payload = 'test_payload';
        $invalidSignature = 'invalid_signature';

        $result = $this->sallaService->verifyWebhookSignature($payload, $invalidSignature);

        $this->assertFalse($result);
    }

    public function test_format_order_for_ai(): void
    {
        $order = [
            'reference_id' => 'ORD-123',
            'status' => ['name' => 'processing'],
            'total' => ['amount' => '150.00', 'currency' => 'SAR'],
            'items' => [
                ['product' => ['name' => 'Product A'], 'quantity' => 2],
                ['product' => ['name' => 'Product B'], 'quantity' => 1],
            ],
            'shipping' => [
                'status' => ['name' => 'shipped'],
                'estimated_delivery' => '2024-12-01',
            ],
        ];

        $formatted = $this->sallaService->formatOrderForAI($order);

        $this->assertStringContainsString('ORD-123', $formatted);
        $this->assertStringContainsString('processing', $formatted);
        $this->assertStringContainsString('150.00 SAR', $formatted);
        $this->assertStringContainsString('Product A x2', $formatted);
        $this->assertStringContainsString('Product B x1', $formatted);
        $this->assertStringContainsString('shipped', $formatted);
        $this->assertStringContainsString('2024-12-01', $formatted);
    }
}
