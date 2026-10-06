<?php

namespace Tests\Feature;

use App\Models\Bot;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Product;
use App\Models\User;
use App\Jobs\ProcessAutoReply;
use App\Services\EcommerceChannelResolver;
use App\Services\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommerceContextResolverTest extends TestCase
{
    use RefreshDatabase;

    private function makeBusiness(): array
    {
        $user = User::factory()->create();
        $business = BusinessProfile::create(['user_id' => $user->id, 'name' => 'Commerce test']);

        return [$user, $business];
    }

    private function makeChannel(User $user, BusinessProfile $business, string $type, string $name): Channel
    {
        return Channel::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => $type,
            'page_id' => strtolower(str_replace(' ', '-', $name)),
            'page_name' => $name,
            'access_token' => '',
            'status' => 'connected',
            'ai_enabled' => true,
        ]);
    }

    private function makeConversation(BusinessProfile $business, Bot $bot, Channel $channel): Conversation
    {
        return Conversation::create([
            'business_id' => $business->id,
            'bot_id' => $bot->id,
            'channel_id' => $channel->id,
            'sender_id' => 'test-customer',
            'status' => 'open',
        ]);
    }

    public function test_resolves_bot_default_among_multiple_provider_accounts(): void
    {
        [$user, $business] = $this->makeBusiness();
        $chat = $this->makeChannel($user, $business, 'instagram', 'Fashion Instagram');
        $shopA = $this->makeChannel($user, $business, 'shopify', 'Shopify A');
        $shopB = $this->makeChannel($user, $business, 'shopify', 'Shopify B');
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Fashion',
            'status' => 'active',
        ]);
        $bot->ecommerceConnections()->attach([
            $shopA->id => ['is_enabled' => true, 'is_default' => false],
            $shopB->id => ['is_enabled' => true, 'is_default' => true],
        ]);
        $conversation = $this->makeConversation($business, $bot, $chat);

        $context = app(EcommerceChannelResolver::class)->resolveConversation($conversation, $bot);

        $this->assertSame('resolved', $context['status']);
        $this->assertSame('bot_default', $context['source']);
        $this->assertSame($shopB->id, $context['connection']->id);
        $this->assertSame($shopB->id, $conversation->fresh()->ecommerce_connection_id);
    }

    public function test_channel_route_precedes_bot_default_only_when_allowed_for_bot(): void
    {
        [$user, $business] = $this->makeBusiness();
        $storefront = $this->makeChannel($user, $business, 'facebook', 'Storefront');
        $salla = $this->makeChannel($user, $business, 'salla', 'Salla');
        $shopify = $this->makeChannel($user, $business, 'shopify', 'Shopify');
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Bot', 'status' => 'active']);
        $bot->ecommerceConnections()->attach([
            $salla->id => ['is_enabled' => true, 'is_default' => true],
            $shopify->id => ['is_enabled' => true, 'is_default' => false],
        ]);
        $storefront->update(['default_ecommerce_connection_id' => $shopify->id]);
        $conversation = $this->makeConversation($business, $bot, $storefront);

        $context = app(EcommerceChannelResolver::class)->resolveConversation($conversation, $bot);

        $this->assertSame('channel', $context['source']);
        $this->assertSame($shopify->id, $context['connection']->id);
    }

    public function test_resolved_conversation_context_stays_sticky(): void
    {
        [$user, $business] = $this->makeBusiness();
        $chat = $this->makeChannel($user, $business, 'instagram', 'Instagram');
        $first = $this->makeChannel($user, $business, 'shopify', 'Shopify Egypt');
        $second = $this->makeChannel($user, $business, 'shopify', 'Shopify Saudi');
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Bot', 'status' => 'active']);
        $bot->ecommerceConnections()->attach([
            $first->id => ['is_enabled' => true, 'is_default' => false],
            $second->id => ['is_enabled' => true, 'is_default' => true],
        ]);
        $conversation = $this->makeConversation($business, $bot, $chat);
        $conversation->forceFill([
            'ecommerce_connection_id' => $first->id,
            'commerce_context_status' => 'resolved',
        ])->save();

        $context = app(EcommerceChannelResolver::class)->resolveConversation($conversation, $bot);

        $this->assertSame('conversation', $context['source']);
        $this->assertSame($first->id, $context['connection']->id);
    }

    public function test_multiple_allowed_stores_without_route_or_default_are_unresolved(): void
    {
        [$user, $business] = $this->makeBusiness();
        $chat = $this->makeChannel($user, $business, 'instagram', 'Instagram');
        $first = $this->makeChannel($user, $business, 'salla', 'Salla');
        $second = $this->makeChannel($user, $business, 'woocommerce', 'Wholesale');
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Bot', 'status' => 'active']);
        $bot->ecommerceConnections()->attach([
            $first->id => ['is_enabled' => true, 'is_default' => false],
            $second->id => ['is_enabled' => true, 'is_default' => false],
        ]);
        $conversation = $this->makeConversation($business, $bot, $chat);

        $context = app(EcommerceChannelResolver::class)->resolveConversation($conversation, $bot);

        $this->assertSame('unresolved', $context['status']);
        $this->assertNull($context['connection']);
        $this->assertNull($conversation->fresh()->ecommerce_connection_id);
    }

    public function test_single_enabled_store_is_automatically_resolved(): void
    {
        [$user, $business] = $this->makeBusiness();
        $chat = $this->makeChannel($user, $business, 'whatsapp', 'Support');
        $store = $this->makeChannel($user, $business, 'salla', 'Only Store');
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Bot', 'status' => 'active']);
        $bot->ecommerceConnections()->attach($store->id, ['is_enabled' => true, 'is_default' => false]);
        $conversation = $this->makeConversation($business, $bot, $chat);

        $context = app(EcommerceChannelResolver::class)->resolveConversation($conversation, $bot);

        $this->assertSame('single_available', $context['source']);
        $this->assertSame($store->id, $context['connection']->id);
    }

    public function test_stale_cross_business_conversation_connection_fails_closed(): void
    {
        [$userA, $businessA] = $this->makeBusiness();
        [$userB, $businessB] = $this->makeBusiness();
        $chat = $this->makeChannel($userA, $businessA, 'instagram', 'Instagram');
        $otherBusinessStore = $this->makeChannel($userB, $businessB, 'shopify', 'Private Store');
        $bot = Bot::create(['business_profile_id' => $businessA->id, 'name' => 'Bot', 'status' => 'active']);
        $conversation = $this->makeConversation($businessA, $bot, $chat);
        $conversation->forceFill([
            'ecommerce_connection_id' => $otherBusinessStore->id,
            'commerce_context_status' => 'resolved',
        ])->save();

        $context = app(EcommerceChannelResolver::class)->resolveConversation($conversation, $bot);

        $this->assertSame('unresolved', $context['status']);
        $this->assertNull($context['connection']);
    }

    public function test_disabled_bot_connection_is_not_revived_by_single_store_fallback(): void
    {
        [$user, $business] = $this->makeBusiness();
        $chat = $this->makeChannel($user, $business, 'instagram', 'Instagram');
        $store = $this->makeChannel($user, $business, 'shopify', 'Disabled Store');
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Bot',
            'status' => 'active',
            'ecommerce_channel_id' => $store->id,
        ]);
        $bot->ecommerceConnections()->attach($store->id, [
            'is_enabled' => false,
            'is_default' => true,
        ]);
        $conversation = $this->makeConversation($business, $bot, $chat);

        $context = app(EcommerceChannelResolver::class)->resolveConversation($conversation, $bot);

        $this->assertSame('unresolved', $context['status']);
        $this->assertNull($context['connection']);
    }

    public function test_woocommerce_order_creation_uses_the_passed_connection_credentials_and_product(): void
    {
        [$user, $business] = $this->makeBusiness();
        $woo = $this->makeChannel($user, $business, 'woocommerce', 'Wholesale');
        $woo->update(['metadata' => [
            'store_url' => 'https://wholesale.example',
            'consumer_key' => encrypt('ck_test'),
            'consumer_secret' => encrypt('cs_test'),
        ]]);
        Http::fake([
            'wholesale.example/wp-json/wc/v3/orders' => Http::response([
                'id' => 901,
                'number' => '901',
                'status' => 'pending',
                'total' => '25.00',
                'currency' => 'USD',
            ], 201),
        ]);

        $result = app(WooCommerceService::class)->createOrderForChannel($woo, [
            'commerce_external_id' => '77',
            'product_name' => 'Sample',
            'full_name' => 'Alex Customer',
            'phone' => '+15555550123',
            'address' => '1 Main Street',
            'quantity' => 2,
        ]);

        $this->assertSame(901, $result['data']['id']);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'wholesale.example/wp-json/wc/v3/orders')
                && ($request['line_items'][0]['product_id'] ?? null) === 77
                && ($request['line_items'][0]['quantity'] ?? null) === 2
                && ($request['billing']['phone'] ?? null) === '+15555550123';
        });
    }

    public function test_shopify_order_creation_uses_the_sticky_store_and_its_synced_variant(): void
    {
        [$user, $business] = $this->makeBusiness();
        $chat = $this->makeChannel($user, $business, 'instagram', 'Instagram');
        $shopA = $this->makeChannel($user, $business, 'shopify', 'shop-a.myshopify.com');
        $shopB = $this->makeChannel($user, $business, 'shopify', 'shop-b.myshopify.com');
        $bot = Bot::create(['business_profile_id' => $business->id, 'name' => 'Bot', 'status' => 'active']);
        $bot->ecommerceConnections()->attach([
            $shopA->id => ['is_enabled' => true, 'is_default' => false],
            $shopB->id => ['is_enabled' => true, 'is_default' => true],
        ]);
        Product::create([
            'business_id' => $business->id,
            'commerce_channel_id' => $shopA->id,
            'commerce_external_id' => 'gid://shopify/Product/101',
            'name' => 'Egypt dress',
            'price' => 100,
            'stock_quantity' => 4,
            'is_active' => true,
            'metadata' => ['variants' => [['id' => 'gid://shopify/ProductVariant/1001']]],
        ]);
        Product::create([
            'business_id' => $business->id,
            'commerce_channel_id' => $shopB->id,
            'commerce_external_id' => 'gid://shopify/Product/101',
            'name' => 'Saudi dress',
            'price' => 200,
            'stock_quantity' => 4,
            'is_active' => true,
            'metadata' => ['variants' => [['id' => 'gid://shopify/ProductVariant/2001']]],
        ]);
        $conversation = $this->makeConversation($business, $bot, $chat);
        $conversation->forceFill([
            'ecommerce_connection_id' => $shopA->id,
            'commerce_context_status' => 'resolved',
            'commerce_context_source' => 'conversation',
            'checkout_state' => ['status' => 'collecting_info'],
        ])->save();
        Http::fake([
            'shop-a.myshopify.com/admin/api/2026-07/orders.json' => Http::response([
                'order' => ['id' => 7001, 'order_number' => 7001],
            ], 201),
            'shop-b.myshopify.com/*' => Http::response([], 500),
        ]);

        $job = new ProcessAutoReply(0);
        $method = new \ReflectionMethod(ProcessAutoReply::class, 'createRealExternalOrder');
        $method->setAccessible(true);
        $result = $method->invoke($job, $conversation, [
            'salla_product_id' => 'gid://shopify/Product/101',
            'commerce_external_id' => 'gid://shopify/Product/101',
            'commerce_channel_id' => $shopA->id,
            'full_name' => 'Alex Customer',
            'phone' => '+201000000000',
            'address' => 'Cairo',
            'product_name' => 'Egypt dress',
            'product_price' => 100,
            'product_currency' => 'EGP',
        ], $chat);

        $this->assertSame('7001', $result['order_id']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'shop-a.myshopify.com')
            && ($request['order']['line_items'][0]['variant_id'] ?? null) === 1001);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'shop-b.myshopify.com'));
    }
}
