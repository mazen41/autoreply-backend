<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;
use App\Models\User;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Sequence;
use App\Models\SequenceEnrollment;
use App\Models\Package;
use App\Jobs\ProcessAutoReply;

class CheckoutOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['services.groq.api_key' => 'test-groq-key']);
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
    public function it_extracts_and_merges_address_without_overwriting_and_creates_order_on_explicit_confirmation()
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'NazBiz Store',
            'ai_provider' => 'groq',
        ]);
        try { Redis::del("rate_limit:{$business->id}:{$user->id}"); } catch (\Throwable) {}

        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test_token',
            'ai_enabled' => true,
            'ai_enabled' => true,
        ]);

        $conversation = Conversation::factory()->create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '+966501234567',
            'sender_name' => 'Ahmed Ali',
            'ai_enabled' => true,
            'checkout_state' => [
                'salla_product_id' => '987654',
                'product_name' => 'Smart Watch',
                'product_price' => 350,
                'product_currency' => 'SAR',
                'full_name' => 'Ahmed Ali',
                'phone' => '+966501234567',
            ],
        ]);

        $addressText = '36 Sayed Abdel Rahman Al-Adawi Street, Faisal, Giza';
        $msg1 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $addressText,
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'success' => true,
                                'reply' => "Here is your order summary:\nProduct: Smart Watch\nName: Ahmed Ali\nPhone: +966501234567\nAddress: {$addressText}\nTotal: 350 SAR\n\nPlease reply 'yes' or 'confirm' to place your order! 🛒",
                                'intent' => 'place_order',
                                'needs_escalation' => false,
                                'confidence' => 0.99,
                                'escalation_reason' => 'none',
                                'needs_images' => false,
                            ])
                        ]
                    ]
                ]
            ], 200),
            'api.salla.dev/admin/v2/countries' => Http::response(['data' => [
                ['id' => 1473353380, 'code' => 'SA'], ['id' => 1723506348, 'code' => 'EG'],
            ]], 200),
            'api.salla.dev/admin/v2/countries/*/cities' => Http::response(['data' => [
                ['id' => 10, 'name' => 'Giza', 'name_en' => 'Giza'],
                ['id' => 1, 'name' => 'Riyadh', 'name_en' => 'Riyadh'],
                ['id' => 2, 'name' => 'Jeddah', 'name_en' => 'Jeddah'],
                ['id' => 11, 'name' => 'Cairo', 'name_en' => 'Cairo'],
            ]], 200),
            'api.salla.dev/admin/v2/customers*' => Http::response([
                'data' => [['id' => 7711, 'mobile' => '966501234567']]
            ], 200),
            'api.salla.dev/admin/v2/orders' => Http::response([
                'status' => 200,
                'data' => ['id' => 889900, 'reference_id' => 'SAL-889900']
            ], 200),
            'api.salla.dev/*' => Http::response(['data' => []], 200)
        ]);

        $job1 = new ProcessAutoReply($msg1->id);
        $job1->handle();

        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('Ahmed Ali', $conversation->checkout_state['full_name']);
        $this->assertEquals('+966501234567', $conversation->checkout_state['phone']);
        $this->assertEquals($addressText, $conversation->checkout_state['address']);
        $this->assertEmpty($conversation->checkout_state['order_id'] ?? null);

        $msg2 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'yes confirm please',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        $job2 = new ProcessAutoReply($msg2->id);
        $job2->handle();

        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('completed', $conversation->checkout_state['status']);
        $this->assertEquals('SAL-889900', $conversation->checkout_state['order_id']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/orders')
            && ($request['ship_to']['country'] ?? null) === 1473353380);

        // Idempotency check: duplicate confirmation turn
        $msg3 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'yes confirm please',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        $job3 = new ProcessAutoReply($msg3->id);
        $job3->handle();

        $conversation->refresh();
        $this->assertEquals('SAL-889900', $conversation->checkout_state['order_id']);
    }

    /** @test */
    public function it_handles_salla_422_failure_and_does_not_create_order_or_trigger_sequence()
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id, 'ai_provider' => 'groq']);
        try { Redis::del("rate_limit:{$business->id}:{$user->id}"); } catch (\Throwable) {}
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test_token',
            'ai_enabled' => true,
        ]);

        $sequence = Sequence::create([
            'business_id' => $business->id,
            'name' => 'VIP Post-Purchase Sequence',
            'trigger_type' => 'order_created',
            'is_active' => true,
        ]);

        $conversation = Conversation::factory()->create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '+966501234567',
            'sender_name' => 'Sara Mohamed',
            'ai_enabled' => true,
            'checkout_state' => [
                'salla_product_id' => '1296071307',
                'product_name' => 'Perfume',
                'product_price' => 200,
                'full_name' => 'Sara Mohamed',
                'phone' => '+966501234567',
                'address' => 'Riyadh Al Olaya',
            ],
        ]);

        $msg = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'yes confirm order',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'success' => true,
                    'reply' => 'Processing your order.',
                    'intent' => 'place_order',
                    'needs_escalation' => false,
                    'confidence' => 0.99,
                    'escalation_reason' => 'none',
                    'needs_images' => false,
                ])]]]
            ], 200),
            'api.salla.dev/admin/v2/countries' => Http::response(['data' => [
                ['id' => 1473353380, 'code' => 'SA'],
            ]], 200),
            'api.salla.dev/admin/v2/countries/*/cities' => Http::response([
                'data' => [['id' => 1, 'name' => 'Riyadh', 'name_en' => 'Riyadh']]
            ], 200),
            'api.salla.dev/admin/v2/customers*' => Http::response([
                'data' => [['id' => 5544, 'mobile' => '966501234567']]
            ], 200),
            'api.salla.dev/admin/v2/orders' => Http::response([
                'status' => 422,
                'error' => [
                    'message' => 'The given data was invalid.',
                    'fields' => ['customer_id' => ['The customer id field is required.']]
                ]
            ], 422),
            'api.salla.dev/*' => Http::response(['data' => []], 200)
        ]);

        $job = new ProcessAutoReply($msg->id);
        $job->handle();

        $conversation->refresh();

        $this->assertNotEquals('completed', $conversation->checkout_state['status'] ?? null);
        $this->assertEmpty($conversation->checkout_state['order_id'] ?? null);
        $failedOrderReply = Message::where('conversation_id', $conversation->id)
            ->where('is_ai', true)
            ->orderByDesc('id')
            ->value('content');
        $this->assertStringContainsString('technical issue processing your order', $failedOrderReply);
        $this->assertStringNotContainsString('order has been placed', mb_strtolower($failedOrderReply));

        $enrollmentCount = SequenceEnrollment::where('conversation_id', $conversation->id)
            ->where('sequence_id', $sequence->id)
            ->count();
        $this->assertEquals(0, $enrollmentCount);
    }

    /** @test */
    public function it_does_not_claim_an_order_was_placed_when_no_salla_store_is_connected()
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'NazBiz Store',
            'ai_provider' => 'groq',
        ]);
        try { Redis::del("rate_limit:{$business->id}:{$user->id}"); } catch (\Throwable) {}

        $instagramChannel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'instagram',
            'status' => 'connected',
            'access_token' => 'instagram-token',
            'ai_enabled' => true,
        ]);
        Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'token_expired',
            'access_token' => 'expired-token',
        ]);

        $conversation = Conversation::factory()->create([
            'channel_id' => $instagramChannel->id,
            'business_id' => $business->id,
            'sender_id' => 'instagram-customer-123',
            'ai_enabled' => true,
            'checkout_state' => [
                'salla_product_id' => '987654',
                'product_name' => 'Smart Watch',
                'product_price' => 350,
                'product_currency' => 'SAR',
                'full_name' => 'Ahmed Ali',
                'phone' => '+966501234567',
                'customer_phone' => '+966501234567',
                'address' => 'Riyadh, Saudi Arabia',
            ],
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'yes please confirm',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'success' => true,
                        'reply' => 'Your order has been placed successfully!',
                        'intent' => 'place_order',
                        'needs_escalation' => false,
                        'confidence' => 1.0,
                        'escalation_reason' => 'none',
                        'needs_images' => false,
                    ])],
                ]],
            ], 200),
            '*' => Http::response([], 200),
        ]);

        (new ProcessAutoReply($message->id))->handle();

        $reply = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'outbound')
            ->latest('id')
            ->first();

        $this->assertNotNull($reply);
        $this->assertStringNotContainsString('placed successfully', mb_strtolower($reply->content));
        $this->assertNotEquals('completed', $conversation->fresh()->checkout_state['status'] ?? null);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.salla.dev'));
    }

    /** @test */
    public function it_allows_retry_after_salla_failure_and_succeeds_when_api_returns_2xx()
    {
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create(['user_id' => $user->id, 'ai_provider' => 'groq']);
        try { Redis::del("rate_limit:{$business->id}:{$user->id}"); } catch (\Throwable) {}
        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test_token',
            'ai_enabled' => true,
        ]);

        $conversation = Conversation::factory()->create([
            'channel_id' => $channel->id,
            'business_id' => $business->id,
            'sender_id' => '+966501234567',
            'sender_name' => 'Mazen',
            'ai_enabled' => true,
            'checkout_state' => [
                'salla_product_id' => '1296071307',
                'product_name' => 'Bag',
                'product_price' => 150,
                'full_name' => 'Mazen',
                'phone' => '+966501234567',
                'address' => 'Jeddah',
            ],
        ]);

        $msg1 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'yes confirm order',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'success' => true,
                    'reply' => 'Processing.',
                    'intent' => 'place_order',
                    'needs_escalation' => false,
                    'confidence' => 0.99,
                    'escalation_reason' => 'none',
                    'needs_images' => false,
                ])]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'success' => true,
                    'reply' => 'Order placed successfully!',
                    'intent' => 'place_order',
                    'needs_escalation' => false,
                    'confidence' => 0.99,
                    'escalation_reason' => 'none',
                    'needs_images' => false,
                ])]]]], 200),
            'api.salla.dev/admin/v2/countries' => Http::response(['data' => [
                ['id' => 1473353380, 'code' => 'SA'],
            ]], 200),
            'api.salla.dev/admin/v2/countries/*/cities' => Http::response([
                'data' => [['id' => 2, 'name' => 'Jeddah', 'name_en' => 'Jeddah']]
            ], 200),
            'api.salla.dev/admin/v2/customers*' => Http::response([
                'data' => [['id' => 3322, 'mobile' => '966501234567']]
            ], 200),
            'api.salla.dev/admin/v2/orders' => Http::sequence()
                ->push('Server Error', 500)
                ->push(['status' => 200, 'data' => ['id' => 991122, 'reference_id' => 'SAL-991122']], 200),
            'api.salla.dev/*' => Http::response(['data' => []], 200)
        ]);

        $job1 = new ProcessAutoReply($msg1->id);
        $job1->handle();

        $conversation->refresh();
        $this->assertNotEquals('completed', $conversation->checkout_state['status'] ?? null);
        $this->assertEmpty($conversation->checkout_state['order_id'] ?? null);

        $msg2 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'yes confirm order',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        $job2 = new ProcessAutoReply($msg2->id);
        $job2->handle();

        $conversation->refresh();
        $this->assertEquals('completed', $conversation->checkout_state['status']);
        $this->assertEquals('SAL-991122', $conversation->checkout_state['order_id']);
    }
}
