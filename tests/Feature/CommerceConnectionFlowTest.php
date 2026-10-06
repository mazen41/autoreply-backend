<?php

namespace Tests\Feature;

use App\Jobs\SyncCommerceStore;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CommerceConnectionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\App\Http\Middleware\PlanEnforcement::class);
        Config::set('app.frontend_url', 'https://app.nazbiz.io');
        Config::set('app.url', 'https://api.nazbiz.io');
    }

    public function test_shopify_legacy_install_starts_authorization_and_finishes_callback(): void
    {
        Queue::fake();
        Config::set('services.shopify.client_id', 'shopify-client-id');
        Config::set('services.shopify.client_secret', 'shopify-client-secret');
        Config::set('services.shopify.redirect', 'https://api.nazbiz.io/api/channels/callback/shopify');
        Config::set('services.shopify.scopes', 'read_products,read_orders');
        Config::set('services.shopify.api_version', '2026-07');
        Config::set('services.shopify.use_legacy_install_flow', true);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $start = $this->postJson('/api/channels/shopify/connect', ['shop_domain' => 'https://mystore.myshopify.com/'])
            ->assertOk()
            ->json('authorization_url');

        parse_str((string) parse_url($start, PHP_URL_QUERY), $authorization);
        $this->assertSame('mystore.myshopify.com', parse_url($start, PHP_URL_HOST));
        $this->assertSame('shopify-client-id', $authorization['client_id']);
        $this->assertSame('https://api.nazbiz.io/api/channels/callback/shopify', $authorization['redirect_uri']);
        $this->assertSame('read_products,read_orders', $authorization['scope']);
        $this->assertNotEmpty($authorization['state']);

        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/admin/oauth/access_token')) {
                return Http::response(['access_token' => 'shop-access-token', 'scope' => 'read_products,read_orders'], 200);
            }
            if (str_contains($request->body(), 'query { shop')) {
                return Http::response(['data' => ['shop' => [
                    'id' => 'gid://shopify/Shop/123',
                    'name' => 'My Store',
                    'myshopifyDomain' => 'mystore.myshopify.com',
                ]]], 200);
            }
            return Http::response(['data' => ['webhookSubscriptionCreate' => [
                'userErrors' => [],
                'webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/1'],
            ]]], 200);
        });

        $callback = [
            'code' => 'one-time-code',
            'shop' => 'mystore.myshopify.com',
            'state' => $authorization['state'],
            'timestamp' => (string) now()->timestamp,
        ];
        $signed = $callback;
        ksort($signed, SORT_STRING);
        $callback['hmac'] = hash_hmac('sha256', http_build_query($signed, '', '&', PHP_QUERY_RFC3986), 'shopify-client-secret');

        $this->get('/api/channels/callback/shopify?' . http_build_query($callback))
            ->assertRedirect('https://app.nazbiz.io/dashboard/channels?success=shopify_connected');

        $this->assertDatabaseHas('channels', [
            'user_id' => $user->id,
            'type' => 'shopify',
            'page_id' => 'mystore.myshopify.com',
            'page_name' => 'My Store',
            'status' => 'connected',
        ]);
        Queue::assertPushed(SyncCommerceStore::class);
    }

    public function test_shopify_managed_install_is_rejected_with_actionable_configuration_error(): void
    {
        Config::set('services.shopify.client_id', 'shopify-client-id');
        Config::set('services.shopify.client_secret', 'shopify-client-secret');
        Config::set('services.shopify.redirect', 'https://api.nazbiz.io/api/channels/callback/shopify');
        Config::set('services.shopify.use_legacy_install_flow', false);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/channels/shopify/connect', ['shop_domain' => 'mystore.myshopify.com'])
            ->assertStatus(409)
            ->assertJsonPath('error', fn (string $error) => str_contains($error, 'embedded=false'));
    }

    public function test_woocommerce_return_waits_for_verified_server_callback_result(): void
    {
        Queue::fake();
        Http::fake(function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/wp-json/wc/v3/system_status')) {
                return Http::response(['settings' => ['store_name' => 'Woo Store']], 200);
            }
            if (str_ends_with($request->url(), '/wp-json/wc/v3/webhooks')) {
                return Http::response(['id' => 44], 201);
            }
            return Http::response([], 200);
        });

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $authorizationUrl = $this->postJson('/api/channels/woocommerce/connect', [
            'store_url' => 'https://woo.example.com',
        ])->assertOk()->json('authorization_url');

        parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorization);
        parse_str((string) parse_url($authorization['return_url'], PHP_URL_QUERY), $returnParams);
        $state = $authorization['user_id'];
        $this->assertSame('woocommerce', $returnParams['connection']);
        $this->assertSame($state, $returnParams['connection_state']);
        $this->assertStringContainsString('/wc-auth/v1/authorize', $authorizationUrl);

        $this->getJson('/api/channels/woocommerce/connection-status?state=' . urlencode($state))
            ->assertOk()->assertJsonPath('status', 'pending');

        $this->postJson('/api/channels/woocommerce/callback', [
            'user_id' => $state,
            'consumer_key' => 'ck_woocommerce_key',
            'consumer_secret' => 'cs_woocommerce_secret',
            'key_permissions' => 'read_write',
        ])->assertOk()->assertJsonPath('success', true);

        $this->getJson('/api/channels/woocommerce/connection-status?state=' . urlencode($state))
            ->assertOk()->assertJsonPath('status', 'connected');
        $this->assertDatabaseHas('channels', [
            'user_id' => $user->id,
            'type' => 'woocommerce',
            'page_id' => 'https://woo.example.com',
            'page_name' => 'Woo Store',
            'status' => 'connected',
        ]);
        Queue::assertPushed(SyncCommerceStore::class);
    }

    public function test_woocommerce_connection_result_is_private_to_owning_user(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $state = 'private-flow-state';
        Cache::put('woocommerce_connection_result:' . hash('sha256', $state), [
            'user_id' => $owner->id,
            'status' => 'pending',
        ], now()->addMinutes(20));

        Sanctum::actingAs($otherUser);
        $this->getJson('/api/channels/woocommerce/connection-status?state=' . urlencode($state))
            ->assertNotFound();
    }
}
