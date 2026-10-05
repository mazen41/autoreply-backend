<?php

namespace Tests\Unit;

use App\Models\Channel;
use App\Services\SallaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SallaServiceTest extends TestCase
{
    use RefreshDatabase;

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

        $this->assertStringContainsString('accounts.salla.sa/oauth2/auth', $url);
        $this->assertStringContainsString('client_id=test_client_id', $url);
        $this->assertStringContainsString('state=test_state', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('metadata.read', $url);
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

    public function test_missing_scope_does_not_refresh_or_mark_channel_token_expired(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
        ]);

        Http::fake([
            'https://api.salla.dev/admin/v2/countries' => Http::response([
                'status' => 401,
                'success' => false,
                'error' => [
                    'code' => 'Unauthorized',
                    'message' => 'The access token should have access to one of those scopes: metadata.read,metadata.read_write',
                ],
            ], 401),
            '*' => Http::response([], 500),
        ]);

        $service = new class extends SallaService {
            public function callForChannel(Channel $channel): array
            {
                return $this->apiCallForChannel($channel, 'GET', '/countries');
            }
        };

        try {
            $service->callForChannel($channel);
            $this->fail('Expected missing-scope exception was not thrown.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Salla API scope missing', $exception->getMessage());
        }

        $this->assertSame('connected', $channel->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_missing_scope_after_token_refresh_preserves_refreshed_credentials_and_channel_status(): void
    {
        $channel = Channel::factory()->create([
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'old-access-token',
            'refresh_token' => 'old-refresh-token',
        ]);

        $scopeDenied = [
            'status' => 401,
            'success' => false,
            'error' => [
                'code' => 'Unauthorized',
                'message' => 'The access token should have access to one of those scopes: metadata.read,metadata.read_write',
            ],
        ];

        Http::fake([
            'https://api.salla.dev/admin/v2/countries' => Http::sequence()
                ->push(['error' => ['message' => 'Access token expired']], 401)
                ->push($scopeDenied, 401),
            'https://accounts.salla.sa/oauth2/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $service = new class extends SallaService {
            public function callForChannel(Channel $channel): array
            {
                return $this->apiCallForChannel($channel, 'GET', '/countries');
            }
        };

        try {
            $service->callForChannel($channel);
            $this->fail('Expected missing-scope exception was not thrown.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Salla API scope missing', $exception->getMessage());
        }

        Http::assertSentCount(3);
        $channel->refresh();
        $this->assertSame('connected', $channel->status);
        $this->assertSame('new-access-token', $channel->access_token);
        $this->assertSame('new-refresh-token', $channel->refresh_token);
    }
}
