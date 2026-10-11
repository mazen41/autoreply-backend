<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use App\Models\User;
use App\Models\BusinessProfile;
use App\Models\Bot;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProductMessageMap;
use App\Models\Package;
use App\Jobs\ProcessAutoReply;

class SallaOrderStateRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.groq.api_key' => 'test-groq-key']);
        
        // Suppress expected logs during test to keep output clean
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        Package::create([
            'name' => 'Free',
            'name_ar' => 'Free',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'ai_replies_limit' => -1,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function it_persists_checkout_state_across_turns_and_recovers_when_api_fails()
    {
        // 1. Setup Data
        $user = User::factory()->create();
        
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'NazBiz Clothes',
            'ai_provider' => 'groq',
        ]);
        try { Redis::del("rate_limit:{$business->id}:{$user->id}"); } catch (\Throwable) {}
        
        $commerceChannel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'valid_token',
        ]);
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'status' => 'connected',
            'access_token' => 'test-instagram-token',
            'ai_enabled' => true,
        ]);
        $bot = Bot::create([
            'business_profile_id' => $business->id,
            'name' => 'Salla Order Recovery Agent',
            'status' => 'active',
            'ecommerce_channel_id' => $commerceChannel->id,
        ]);
        $bot->channels()->attach($channel->id, ['is_primary' => true]);
        DB::table('salla_cities')->insert([
            'channel_id' => $commerceChannel->id,
            'country_code' => 'EG',
            'country_id' => 1723506348,
            'salla_city_id' => 1,
            'name' => 'Cairo',
            'name_en' => 'Cairo',
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $conversation = Conversation::factory()->create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'bot_id' => $bot->id,
            'ecommerce_connection_id' => $commerceChannel->id,
            'sender_id' => '+201152879755',
            'ai_enabled' => true,
        ]);

        // Create a prior ProductMessageMap indicating a product was previously sent
        $quotedMessageId = 'WHATSAPP_MSG_ID_123';
        ProductMessageMap::create([
            'conversation_id' => $conversation->id,
            'channel_id' => $channel->id,
            'platform_message_id' => $quotedMessageId,
            'salla_product_id' => '523147668',
            'commerce_channel_id' => $commerceChannel->id,
            'commerce_external_id' => '523147668',
            'provider' => 'instagram',
            'commerce_provider' => 'salla',
            'product_name' => 'Fancy Dress',
            'product_price' => 174,
            'currency' => 'SAR',
            'image_url' => 'https://example.com/dress.jpg',
        ]);

        // 2. TURN 1: Customer says "I want this one" (replying to product)
        $msg1 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'I want this one',
            'direction' => 'inbound',
            'is_ai' => false,
            'metadata' => ['quoted_message_id' => $quotedMessageId],
        ]);

        // Mock LLM call for product selection, detail collection, and the
        // explicit confirmation after the order summary was shown.
        Http::fake([
            'graph.facebook.com/*' => Http::response(['message_id' => 'meta-order-summary'], 200),
            'api.groq.com/*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'success' => true,
                    'reply' => 'Great choice! Can I get your full name and address for delivery?',
                    'intent' => 'place_order',
                    'needs_escalation' => false,
                    'confidence' => 0.99,
                    'escalation_reason' => 'none',
                    'needs_images' => false,
                ])]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'success' => true,
                    'reply' => 'Thanks Mazen! Shall I confirm this order?',
                    'intent' => 'place_order',
                    'needs_escalation' => false,
                    'confidence' => 0.99,
                    'escalation_reason' => 'none',
                    'needs_images' => false,
                ])]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'success' => true,
                    'reply' => 'Your order has been placed!',
                    'intent' => 'place_order',
                    'needs_escalation' => false,
                    'confidence' => 0.99,
                    'escalation_reason' => 'none',
                    'needs_images' => false,
                ])]]]], 200),
            // Mock Salla API returning product details
            'api.salla.dev/admin/v2/products*' => Http::response([
                'data' => [
                    [
                        'id' => 523147668,
                        'name' => 'Fancy Dress',
                        'price' => ['amount' => 174, 'currency' => 'SAR'],
                    ]
                ],
                'pagination' => ['total' => 1, 'count' => 1, 'per_page' => 10, 'current_page' => 1]
            ], 200),
            'api.salla.dev/admin/v2/countries' => Http::response(['data' => [
                ['id' => 1723506348, 'code' => 'EG'],
            ]], 200),
            'api.salla.dev/admin/v2/countries/*/cities' => Http::response([
                'data' => [['id' => 1, 'name' => 'Cairo', 'name_en' => 'Cairo']]
            ], 200),
            'api.salla.dev/admin/v2/customers*' => function ($request) {
                return $request->method() === 'POST'
                    ? Http::response(['data' => ['id' => 7711]], 200)
                    : Http::response(['data' => []], 200);
            },
            'api.salla.dev/admin/v2/shipping/companies/estimate-rate*' => Http::response(['data' => [[
                'company_id' => 5,
                'total' => ['amount' => 20, 'currency' => 'SAR'],
                'services' => [['name' => 'cod', 'amount' => ['amount' => 0]]],
            ]]], 200),
            'api.salla.dev/admin/v2/orders?*' => Http::response(['data' => []], 200),
            'api.salla.dev/admin/v2/orders' => Http::response(['data' => ['id' => 523147668, 'reference_id' => 'SAL-523147668']], 200),
        ]);

        // Run Turn 1
        $job1 = new ProcessAutoReply($msg1->id);
        $job1->handle();

        // Assert checkout_state was populated
        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('523147668', $conversation->checkout_state['salla_product_id']);
        $this->assertEquals('Fancy Dress', $conversation->checkout_state['product_name']);

        // 3. TURN 2: Customer provides the required delivery details.
        $msg2 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'My name is Mazen Hossny. My address is Cairo. My phone is 01152879755. Email is mazen@example.com, postal code 11511, building number 123, coordinates 30.0444,31.2357.',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        // Run Turn 2
        $job2 = new ProcessAutoReply($msg2->id);
        $job2->handle();

        // Assert checkout_state persisted the phone number and kept the product
        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('523147668', $conversation->checkout_state['salla_product_id']); // Did not lose product!
        $this->assertStringContainsString('01152879755', $conversation->checkout_state['phone']); // Extracted phone
        $this->assertSame('confirmation_pending', $conversation->checkout_state['confirmation_state']);

        // Verify the bot didn't send the generic "I'm sorry I'm having a brief issue" message,
        // but instead used the AI reply which had the context
        $botReply2 = Message::where('conversation_id', $conversation->id)
            ->where('is_ai', true)
            ->orderBy('id', 'desc')
            ->first();
            
        $this->assertStringNotContainsString('having a brief issue loading our product catalogue', $botReply2->content);
        $this->assertStringContainsString('Please review your order summary', $botReply2->content);
        
        // 4. TURN 3: Customer confirms order
        $msg3 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'Yes, please confirm',
            'direction' => 'inbound',
            'is_ai' => false,
            'metadata' => ['quoted_message_id' => (string) $conversation->fresh()->checkout_state['confirmation_summary_message_id']],
        ]);

        // Run Turn 3
        $job3 = new ProcessAutoReply($msg3->id);
        $job3->handle();

        // Assert checkout_state contains completed order status with real order ID
        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('completed', $conversation->checkout_state['status']);
        $this->assertNotEmpty($conversation->checkout_state['order_id']);
        $this->assertDatabaseHas('commerce_order_attempts', [
            'conversation_id' => $conversation->id,
            'connection_id' => $commerceChannel->id,
            'status' => 'completed',
            'external_order_id' => $conversation->checkout_state['order_id'],
        ]);

        // Simulate the checkout-state write being absent after the provider
        // accepted the order. The durable attempt must recover its real ID and
        // must not POST another order.
        $completedState = $conversation->checkout_state;
        $retryInput = array_merge($completedState, [
            'commerce_channel_id' => $commerceChannel->id,
            'commerce_external_id' => $completedState['salla_product_id'],
        ]);
        $conversation->update(['checkout_state' => array_diff_key($completedState, array_flip([
            'status', 'order_id', 'order_number', 'order_fingerprint', 'order_product_id', 'order_connection_id',
        ]))]);
        $createOrder = new \ReflectionMethod(ProcessAutoReply::class, 'createRealExternalOrder');
        $createOrder->setAccessible(true);
        $recovered = $createOrder->invoke(new ProcessAutoReply($msg3->id), $conversation->fresh(), $retryInput, $channel);

        $this->assertSame($completedState['order_id'], $recovered['order_id']);
        $providerOrderPosts = Http::recorded(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), 'api.salla.dev/admin/v2/orders'));
        $this->assertCount(1, $providerOrderPosts);
    }
}
