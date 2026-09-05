<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;
use App\Models\User;
use App\Models\BusinessProfile;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\OrderCheckoutService;
use App\Jobs\ProcessAutoReply;

class CheckoutOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();
    }

    /** @test */
    public function it_extracts_and_merges_address_without_overwriting_and_creates_order_on_explicit_confirmation()
    {
        // 1. Setup User, Business, Channel, Conversation
        $user = User::factory()->create();
        $business = BusinessProfile::factory()->create([
            'user_id' => $user->id,
            'name' => 'NazBiz Store',
        ]);

        $channel = Channel::factory()->create([
            'user_id' => $user->id,
            'type' => 'salla',
            'status' => 'connected',
            'access_token' => 'test_token',
        ]);
        $channel->business_id = $business->id;
        $channel->save();

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

        // 2. TURN 1: Customer provides address
        $addressText = '36 Sayed Abdel Rahman Al-Adawi Street, Faisal, Giza';
        $msg1 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => $addressText,
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        // Mock LLM AI reply showing summary with address and asking for confirmation
        Http::fake([
            'api.openai.com/*' => Http::response([
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
            'api.salla.dev/*' => Http::response([
                'data' => ['id' => 12345, 'reference_id' => 'SAL-12345']
            ], 200)
        ]);

        $job1 = new ProcessAutoReply($msg1->id);
        $job1->handle();

        // ASSERTION 1: Address is saved in checkout_state and NOT overwritten!
        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('Ahmed Ali', $conversation->checkout_state['full_name']);
        $this->assertEquals('+966501234567', $conversation->checkout_state['phone']);
        $this->assertEquals($addressText, $conversation->checkout_state['address']);
        $this->assertEmpty($conversation->checkout_state['order_id'] ?? null); // Not created before confirmation!

        // 3. TURN 2: Customer explicitly confirms
        $msg2 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'yes confirm please',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'success' => true,
                                'reply' => 'Your order has been confirmed and placed successfully! 🎉',
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
            'api.salla.dev/*' => Http::response([
                'status' => 200,
                'data' => [
                    'id' => 889900,
                    'reference_id' => 'SAL-889900'
                ]
            ], 200)
        ]);

        $job2 = new ProcessAutoReply($msg2->id);
        $job2->handle();

        // ASSERTION 2: External order created, order_id stored in checkout_state, status completed
        $conversation->refresh();
        $this->assertNotNull($conversation->checkout_state);
        $this->assertEquals('completed', $conversation->checkout_state['status']);
        $this->assertNotEmpty($conversation->checkout_state['order_id']);
        $this->assertEquals($addressText, $conversation->checkout_state['customer']['address'] ?? $conversation->checkout_state['address']);

        // 4. TURN 3 (IDEMPOTENCY TEST): Retry duplicate confirmation turn
        $msg3 = Message::create([
            'conversation_id' => $conversation->id,
            'content' => 'yes confirm please',
            'direction' => 'inbound',
            'is_ai' => false,
        ]);

        $existingOrderId = $conversation->checkout_state['order_id'];

        $job3 = new ProcessAutoReply($msg3->id);
        $job3->handle();

        // ASSERTION 3: Order ID remains unchanged, no duplicate order created
        $conversation->refresh();
        $this->assertEquals($existingOrderId, $conversation->checkout_state['order_id']);
    }
}
